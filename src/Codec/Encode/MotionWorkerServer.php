<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;
use Throwable;

/**
 * @purpose 运动模块分布式计算-服务端
 * @author yanglong
 *
 * 运行在独立的运动搜索子进程中（通常通过 MotionWorkerClient 以本地 socket 连接）。
 * 职责：监听 socket -> 按帧协议拆包 -> 维护每连接的参考帧 -> 调用 MotionWorkerHelper
 * 执行宏块预测 -> 把结果编码回包。
 * 采用单线程 stream_select 事件循环，同时处理「读请求」与「写响应」。
 */
final class MotionWorkerServer
{
    /**
     * 启动服务端主循环。
     *
     * @param string     $address 监听地址（如 tcp://127.0.0.1:8501）
     * @param float|null $idle    空闲退出阈值（秒）；非 null 时，在「至少接受过一个连接、当前无连接、
     *                            且已空闲超过该时长」后关闭监听并退出，避免子进程常驻
     */
    public function run(string $address, ?float $idle = null): void
    {
        // 创建监听套接字；失败时把错误码/错误信息收集到 $errno/$error
        $server = @stream_socket_server($address, $errno, $error);
        // 监听失败属于不可恢复错误，直接抛异常终止子进程
        if ($server === false) throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})");
        // 设为非阻塞，配合 stream_select 实现事件驱动
        stream_set_blocking($server, false);
        // 连接表：key 为 socket 资源 id，value 保存 socket、收/发缓冲区、以及该连接的参考帧缓存
        $connections = [];
        // 是否已接受过至少一个连接（用于空闲退出判断，避免启动瞬间就退出）
        $accepted = false;
        // 记录进入空闲计时的起点时间
        $since = microtime(true);
        // 主事件循环：持续运行直到空闲退出条件满足
        while (true) {
            // 空闲退出：已接客、无现存连接、且空闲时长达到阈值时，关闭监听并返回
            if ($accepted && $idle !== null && !$connections && microtime(true) - $since >= $idle) {
                fclose($server);
                return;
            }
            // 待监听的读集合，初始只有监听套接字
            $read = [$server];
            // 待监听的写集合，初始为空
            $write = [];
            foreach ($connections as $connection) {
                // 所有连接都关注可读事件（可能有新请求到达）
                $read[] = $connection['socket'];
                // 仅当有积压输出时才关注可写事件，避免无谓唤醒
                if ($connection['output'] !== '') $write[] = $connection['socket'];
            }
            // 不关心异常集合
            $except = null;
            // 超时 1 微秒（第三个参数为秒 0、第四个为微秒 1），相当于轮询式事件循环
            if (@stream_select($read, $write, $except, 0, 1) === false) continue;
            // 监听套接字可读：说明有新连接到达
            if (in_array($server, $read, true)) {
                // 循环 accept 直到没有待处理连接（accept 超时为 0，即非阻塞）
                while (($socket = @stream_socket_accept($server, 0)) !== false) {
                    // 新连接同样设为非阻塞
                    stream_set_blocking($socket, false);
                    // 以 socket 资源 id 为键登记连接，初始化空收发缓冲与参考帧表
                    $connections[(int)$socket] = ['socket' => $socket, 'input' => '', 'output' => '', 'frames' => []];
                    // 标记已接客（解除空闲退出的启动保护）
                    $accepted = true;
                }
                // 监听套接字不参与后续「读数据」处理，从读集合中移除
                $read = array_filter($read, fn($socket) => $socket !== $server);
            }
            // 处理所有可读连接：读取字节流并解析出完整请求帧
            foreach ($read as $socket) {
                // 用资源 id 定位连接状态
                $connectionId = (int)$socket;
                // 连接可能已被前面的处理关闭，跳过
                if (!isset($connections[$connectionId])) continue;
                // 一次最多读取 64KB，避免单个连接长期占用事件循环
                $data = @fread($socket, 65536);
                // 读失败，或对端已关闭且无数据：清理连接
                if ($data === false || ($data === '' && feof($socket))) {
                    fclose($socket);
                    unset($connections[$connectionId]);
                    continue;
                }
                // 追加到该连接的输入缓冲区
                $connections[$connectionId]['input'] .= $data;
                // 按帧协议把缓冲区拆成一个个完整请求体（可能一次收到多帧，也可能半帧）
                foreach (MotionWorkerProtocol::takeFrames($connections[$connectionId]['input']) as $body) {
                    // 请求序号初值 0，异常回包时若尚未解出序号也用它占位
                    $request = 0;
                    try {
                        // 解码请求体为消息数组
                        $message = MotionWorkerProtocol::decodeRequest($body);
                        // 参考帧装载请求：缓存该 seq 对应的参考帧，供后续预测请求复用
                        if ($message[0] === MotionWorkerProtocol::LOAD_REFERENCE) {
                            // 解构：忽略首元素（消息类型），其余依次为 seq、宽、高、对齐宽、对齐高、Y/U/V
                            [, $seq, $width, $height, $aw, $ah, $refY, $refU, $refV] = $message;
                            // 每个连接只保留最新一次装载的参考帧（按 seq 索引）
                            $connections[$connectionId]['frames'] = [$seq => [$width, $height, $aw, $ah, $refY, $refU, $refV]];
                            continue;
                        }
                        // 预测请求：seq、请求号、QP、待处理宏块列表
                        [, $seq, $request, $qp, $blocks] = $message;
                        // 必须已有对应 seq 的参考帧，否则协议使用有误
                        if (!isset($connections[$connectionId]['frames'][$seq])) throw new RuntimeException('Unknown motion worker reference seq');
                        // 取出参考帧几何参数与三分量像素数据
                        [$width, $height, $aw, $ah, $refY, $refU, $refV] = $connections[$connectionId]['frames'][$seq];
                        // 为本次请求构造 helper（反量化表在进程内共享，构造成本很低）
                        $helper = new MotionWorkerHelper($width, $height, $aw, $ah, $qp, $refY, $refU, $refV);
                        // T5：batch 头 bit0 = 允许 inter 8x8DCT 双路决策（旧主进程载荷默认 0）
                        // 兼容旧载荷：缺失标志时按 0 处理（即关闭该决策）
                        $helper->inter8x8Enabled = (($message[6] ?? 0) & 0x01) !== 0;
                        // 结果数组：key 与请求中的宏块下标一一对应
                        $result = [];
                        // 逐个宏块执行预测/搜索
                        foreach ($blocks as $index => $block) $result[$index] = $helper->prepare($block);
                        // 把结果编码成响应帧，追加到该连接的输出缓冲区（由写阶段发送）
                        $connections[$connectionId]['output'] .= MotionWorkerProtocol::response($request, $result);
                    } catch (Throwable $exception) {
                        // 单请求失败不应拖垮整个子进程：记录到 STDERR，并回一个错误响应
                        fwrite(STDERR, "Motion worker request {$request} failed: {$exception->getMessage()}\n");
                        $connections[$connectionId]['output'] .= MotionWorkerProtocol::error($request, $exception->getMessage());
                    }
                }
            }
            // 处理所有可写连接：尽量把输出缓冲区发出去
            foreach ($write as $socket) {
                // 用资源 id 定位连接状态
                $connectionId = (int)$socket;
                // 连接已关闭或输出已清空（可能已被读阶段处理）则跳过
                if (!isset($connections[$connectionId]) || $connections[$connectionId]['output'] === '') continue;
                // 非阻塞写；返回值可能小于缓冲区长度（部分写入）
                $written = @fwrite($socket, $connections[$connectionId]['output']);
                // 写失败，或对端已关闭：清理连接
                if ($written === false || ($written === 0 && feof($socket))) {
                    fclose($socket);
                    unset($connections[$connectionId]);
                } elseif ($written > 0) {
                    // 部分写入：把已发送的前缀从缓冲区裁掉，剩余部分留待下次可写事件
                    $connections[$connectionId]['output'] = substr($connections[$connectionId]['output'], $written);
                }
            }
        }
    }
}


