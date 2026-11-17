<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;

/**
 * @purpose 运动模块分布式计算-客户端
 * @author yanglong
 *
 * 运行在主转码进程中，负责：
 * - 按 CPU 核数拉起若干运动搜索子进程（bin/motion-worker.php），并与其建立本地 TCP 连接；
 * - 把一帧的宏块任务按「连续宏块行」切片，分发给各 worker；
 * - 参考帧按内容分配单调序号，内容不变时复用序号以避免整帧重传；
 * - 通过 dispatch()/collect() 实现「派发本帧、等待上一帧」的帧级并行（双缓冲）。
 * 帧协议细节见 MotionWorkerProtocol；服务端见 MotionWorkerServer。
 */
final class MotionWorkerClient
{
    // worker 连接套接字，下标为 worker 编号
    private array $sockets = [];
    // 每个 worker 的接收缓冲（可能包含半帧数据）
    private array $inputs = [];
    // 每个 worker 的发送缓冲（非阻塞写可能只写出一部分）
    private array $outputs = [];
    // 由本进程拉起的子进程句柄（用于析构时回收）
    private array $processes = [];
    // 上一次发送的参考帧三分量内容（用于判断参考帧是否变化）
    private array $lastReference = [];
    // 每个 worker 当前已装载的参考帧序号（用于跳过重复装载）
    private array $workerSeq = [];
    // 每个 worker 使用的端口号（port=0 时动态分配）
    private array $workerPorts = [];
    // 请求 id 发生器（每批 dispatch 内使用，用于响应配对）
    private int $id = 1;
    // 参考帧序号发生器（内容变化时递增）
    private int $refSeq = 0;
    /** 当前在途批次：['ids'=>worker=>requestId, 'total'=>job 总数]，collect 后清空 */
    private ?array $pending = null;

    /**
     * @param int $port    基端口；为 0 时由系统动态分配（每个 worker 一个端口）
     * @param int $workers worker 数量；为 0 时按 CPU 核数自动取值
     */
    public function __construct(private int $port = 0, private int $workers = 0)
    {
        // worker 数：显式指定优先；否则取 CPU 核数，并限制在 1~4 之间（避免进程过多）
        $this->workers = $workers > 0 ? $workers : max(1, min(4, (int)(getenv('NUMBER_OF_PROCESSORS') ?: 2)));
        // 指定了基端口时，各 worker 依次使用 port, port+1, ...
        if ($this->port !== 0) {
            for ($worker = 0; $worker < $this->workers; $worker++) $this->workerPorts[$worker] = $this->port + $worker;
        }
    }

    /**
     * 异步派发一帧：仅写入 socket（非阻塞尽力刷新），不等结果。
     * 帧级双缓冲时主进程随后执行上一帧的 CAVLC，worker 并行计算本帧。
     * 必须先 collect() 上一帧后才能再次 dispatch()。
     *
     * @param array $jobs 光栅顺序连续键 0..mbWidth*mbHeight-1 => [x, y, range]
     */
    public function dispatch(
        int $width,
        int $height,
        int $aw,
        int $ah,
        int $qp,
        string $refY,
        string $refU,
        string $refV,
        string $curY,
        string $curU,
        string $curV,
        int $mbWidth,
        int $mbHeight,
        array $jobs,
        bool $dct8 = false
    ): void {
        // 同一时刻只允许一个在途批次：上一批必须先 collect
        if ($this->pending !== null) throw new RuntimeException('Motion worker batch already in flight');
        // 确保所有 worker 已连接（首次调用时会拉起子进程）
        $this->connectAll();
        // 参考帧按内容分配单调序号：内容不变（如纯静态画面）则复用同一序号，
        // worker 端已持有该参考帧时跳过整帧重传；字符串 === 先比长度再 memcmp，
        // 不同内容通常首字节即返回，比每帧 SHA256 便宜且无碰撞风险。
        // 三分量内容都与上次一致：复用上次的序号
        if ($this->lastReference !== []
            && $this->lastReference[0] === $refY
            && $this->lastReference[1] === $refU
            && $this->lastReference[2] === $refV) {
            $seq = $this->refSeq;
        } else {
            // 内容变化：序号递增，并记录本次内容
            $seq = ++$this->refSeq;
            $this->lastReference = [$refY, $refU, $refV];
        }

        // 任务总数
        $total = count($jobs);
        // 任务必须恰好覆盖整帧所有宏块
        if ($total !== $mbWidth * $mbHeight) throw new RuntimeException('Motion worker jobs must cover every macroblock');
        // 色度每行像素数（宽度按 4:2:0 除以 2）
        $cw = intdiv($aw, 2);
        // 本批各 worker 的请求 id
        $ids = [];
        // 参考帧消息只构造一次，多个 worker 需要时复用同一字节串
        $referenceFrame = null;
        // 按宏块行连续分片：worker w 取连续键区间（=连续宏块行），
        // 每 worker 只发送自己行区间的条带，整帧条带恰好发送一次。
        for ($worker = 0; $worker < $this->workers; $worker++) {
            // 本分片的起始任务下标（按比例切分）
            $kStart = (int)floor($total * $worker / $this->workers);
            // 本分片的结束下标（不含）
            $kEnd = (int)floor($total * ($worker + 1) / $this->workers);
            // 空分片（worker 数多于宏块数）直接跳过
            if ($kStart === $kEnd) continue;
            // 本分片的宏块任务子集
            $chunk = [];
            for ($k = $kStart; $k < $kEnd; $k++) $chunk[$k] = $jobs[$k];
            // 起始宏块行号
            $firstY = intdiv($kStart, $mbWidth);
            // 结束宏块行号
            $lastY = intdiv($kEnd - 1, $mbWidth);
            // 条带在平面上的起始行偏移
            $stripOffset = $firstY;
            // 条带包含的宏块行数
            $stripCount = $lastY - $firstY + 1;
            // 亮度条带：从 curY 中截取对应行区间（每宏块行 16 像素行）
            $strips = substr($curY, $stripOffset * 16 * $aw, $stripCount * 16 * $aw);
            // 色度条带：每宏块行 8 行 U 紧接 8 行 V（色度始终正常编码）
            $chromaStrips = '';
            for ($r = 0; $r < $stripCount; $r++) {
                // 该宏块行在色度平面中的起始行号（4:2:0 每宏块行 8 行色度）
                $cy = ($stripOffset + $r) * 8;
                // 追加 8 行 U
                $chromaStrips .= substr($curU, $cy * $cw, 8 * $cw);
                // 追加 8 行 V
                $chromaStrips .= substr($curV, $cy * $cw, 8 * $cw);
            }

            // 分配请求 id
            $id = $this->id++;
            $ids[$worker] = $id;
            // 若该 worker 尚未装载本序号参考帧，则先发一条参考帧装载消息
            if (($this->workerSeq[$worker] ?? null) !== $seq) {
                // ??= 保证同一次 dispatch 内只构造一次参考帧消息
                $referenceFrame ??= MotionWorkerProtocol::loadReference($seq, $width, $height, $aw, $ah, $refY, $refU, $refV);
                $this->outputs[$worker] .= $referenceFrame;
                // 记录该 worker 已装载的序号
                $this->workerSeq[$worker] = $seq;
            }
            // 追加本批次的预测请求（宏块任务 + 条带像素 + dct8 标志）
            $this->outputs[$worker] .= MotionWorkerProtocol::batch($id, $seq, $qp, $chunk, $strips, $stripOffset, $stripCount, $aw, $chromaStrips, $dct8 ? 1 : 0);
        }
        // 记录在途批次信息，供 collect 配对响应
        $this->pending = ['ids' => $ids, 'total' => $total];
        // 尽力立即把请求刷出去，剩余部分由 collect 的 event loop 排空
        foreach (array_keys($ids) as $worker) {
            if ($this->outputs[$worker] !== '') $this->writeSocket($worker);
        }
    }

    /**
     * 等待并合并在途批次结果（阻塞至全部 worker 响应）。
     *
     * @return array key 为宏块下标（与 dispatch 的 jobs 键一致），value 为该宏块的预测结果
     */
    public function collect(): array
    {
        // 没有在途批次时调用属于使用错误
        if ($this->pending === null) throw new RuntimeException('No motion worker batch in flight');
        // 待收齐的 worker -> 请求 id 映射
        $ids = $this->pending['ids'];
        // 期望的宏块结果总数
        $total = $this->pending['total'];
        // 合并后的结果
        $result = [];
        // 整批超时时间（秒），防止 worker 异常导致主进程永久阻塞
        $deadline = microtime(true) + 30;
        // 逐个 worker 收齐响应
        while ($ids !== []) {
            // 剩余可用时间
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('Timed out motion worker batch');
            $read = [];
            $write = [];
            foreach (array_keys($ids) as $worker) {
                // 仍待响应的 worker 关注可读事件
                $read[] = $this->sockets[$worker];
                // 仍有积压输出时关注可写事件
                if ($this->outputs[$worker] !== '') $write[] = $this->sockets[$worker];
            }
            $except = null;
            // 轮询式等待（超时 1 微秒）
            $ready = @stream_select($read, $write, $except, 0, 1);
            if ($ready === false) throw new RuntimeException('Failed waiting for motion worker');
            // 先处理可写：把剩余请求全部发出（部分写由 writeSocket 内部裁剪缓冲）
            foreach ($write as $socket) $this->writeSocket($this->workerFor($socket));
            // 再处理可读：读取响应
            foreach ($read as $socket) {
                // 由套接字反查 worker 编号
                $worker = $this->workerFor($socket);
                // 追加读取到的字节
                $this->readSocket($worker);
                // 按帧协议拆包（每帧上限 16MB），逐个解析响应
                foreach (MotionWorkerProtocol::takeFrames($this->inputs[$worker], 16) as $body) {
                    // 解出 [响应 id, 成功标志, 数据或错误信息]
                    [$responseId, $ok, $part] = MotionWorkerProtocol::decodeResponse($body);
                    // 响应 id 必须与派发的 id 匹配，否则协议错乱
                    if ($responseId !== $ids[$worker]) throw new RuntimeException("Unexpected motion worker response {$responseId}");
                    // worker 内部异常会回传错误文本
                    if (!$ok) throw new RuntimeException('Motion worker failed: ' . $part);
                    // 把该 worker 负责的宏块结果并入总结果
                    foreach ($part as $key => $value) $result[$key] = $value;
                    // 该 worker 已收齐，移出待收集合
                    unset($ids[$worker]);
                }
            }
        }
        // 清空在途批次，允许下一次 dispatch
        $this->pending = null;
        // 结果数量必须与任务总数一致，否则说明分片/协议有遗漏
        if (count($result) !== $total) throw new RuntimeException('Incomplete motion worker batch');
        // 按宏块下标排序，恢复光栅顺序
        ksort($result);
        return $result;
    }

    /** 同步兼容封装：派发并立即收齐。 */
    public function batch(
        int $width,
        int $height,
        int $aw,
        int $ah,
        int $qp,
        string $refY,
        string $refU,
        string $refV,
        string $curY,
        string $curU,
        string $curV,
        int $mbWidth,
        int $mbHeight,
        array $jobs,
        bool $dct8 = false
    ): array {
        // 先异步派发
        $this->dispatch($width, $height, $aw, $ah, $qp, $refY, $refU, $refV, $curY, $curU, $curV, $mbWidth, $mbHeight, $jobs, $dct8);
        // 再同步收齐并返回
        return $this->collect();
    }

    /**
     * 向系统申请一个空闲端口：监听 127.0.0.1:0 让内核分配，读出端口后立即关闭。
     *
     * @return int 可用端口号
     */
    private function allocatePort(): int
    {
        // 绑定 0 端口：由系统分配临时端口
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new RuntimeException("Unable to reserve motion worker port: {$error} ({$errno})");
        // 取本地 socket 名称（形如 127.0.0.1:port）
        $name = stream_socket_get_name($server, false);
        // 立即释放端口（随后子进程会监听它，存在极小的竞争窗口，故用文件锁串行化分配）
        fclose($server);
        // 截取冒号后的端口号
        return (int)substr(strrchr($name, ':'), 1);
    }

    /**
     * 确保所有 worker 子进程已启动并建立连接。
     */
    public function connectAll(): void
    {
        // 第一阶段：一次性拉起全部子进程（PHP 冷启动并发进行，避免逐个等待）
        // 待连接表：worker => 端口
        $pending = [];
        // worker 入口脚本路径（项目 bin 目录下）
        $entry = dirname(__DIR__, 3) . '/bin/motion-worker.php';
        // 复用当前 Composer autoload，保证子进程加载同一套类
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        // 子进程直接继承 stdin/stdout/stderr，便于日志输出
        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        for ($worker = 0; $worker < $this->workers; $worker++) {
            // 已连接的 worker 直接跳过
            if (isset($this->sockets[$worker]) && is_resource($this->sockets[$worker])) continue;
            // 端口分配用的文件锁句柄
            $lock = null;
            // 尚未分配端口时，在文件锁保护下动态取一个空闲端口
            if (!isset($this->workerPorts[$worker])) {
                $lock = fopen(sys_get_temp_dir() . '/flv2mp4-motion-worker-port.lock', 'c');
                // 拿不到锁说明无法安全分配端口
                if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock motion worker port allocation');
                $this->workerPorts[$worker] = $this->allocatePort();
                // 分配完成后释放锁
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            // 本 worker 端口
            $port = $this->workerPorts[$worker];
            // 指定了基端口时才先尝试连接既有服务（复用外部已启动的 worker）
            $socket = $this->port === 0 ? false : @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
            if ($socket === false) {
                // 多进程转码时多个 PHP 冷启动并发，2 秒窗口会偶发连接超时；放宽到 15 秒
                // 连接失败则拉起新的 worker 子进程（--owned 表示由本客户端负责回收）
                $process = @proc_open([PHP_BINARY, $entry, '--owned', "--port={$port}", "--autoload={$autoload}"], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) throw new RuntimeException('Unable to start motion worker');
                // 记录子进程句柄，析构时终止
                $this->processes[] = $process;
                // 进入第二阶段等待其监听就绪
                $pending[$worker] = $port;
            } else {
                // 复用已存在的 worker：设非阻塞并初始化收发缓冲
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                // 未知对端参考帧状态，清空已装载序号以强制下一次重发参考帧
                unset($this->workerSeq[$worker]);
            }
        }

        // 第二阶段：并发轮询，等待所有子进程监听就绪
        $deadline = microtime(true) + 15;
        while ($pending !== []) {
            // 超时仍未连上：报错并给出未就绪的端口列表
            if (microtime(true) >= $deadline) throw new RuntimeException('Unable to connect motion workers (timeout): ' . implode(',', $pending));
            foreach ($pending as $worker => $port) {
                // 非阻塞式短超时连接（0.1 秒）
                $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
                // 尚未监听就绪：下一轮再试
                if ($socket === false) continue;
                // 连接成功：初始化状态并从待连接表移除
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                unset($this->workerSeq[$worker], $pending[$worker]);
            }
            // 仍有未就绪的 worker 时短暂让出 CPU（1 微秒），避免忙等
            if ($pending !== []) usleep(1);
        }
    }

    /**
     * 由套接字资源反查 worker 编号。
     *
     * @param resource $socket 套接字
     * @return int worker 编号
     */
    private function workerFor($socket): int
    {
        // 线性查找（worker 数量最多 4 个，成本可忽略）
        foreach ($this->sockets as $worker => $candidate) if ($candidate === $socket) return $worker;
        // 未登记过的套接字属于内部错误
        throw new RuntimeException('Unknown motion worker socket');
    }

    /**
     * 把该 worker 输出缓冲中的内容尽力写出（非阻塞，允许部分写）。
     *
     * @param int $worker worker 编号
     */
    private function writeSocket(int $worker): void
    {
        // 返回实际写出的字节数，可能小于缓冲区长度
        $written = @fwrite($this->sockets[$worker], $this->outputs[$worker]);
        // 写失败或对端关闭：直接抛出，由上层终止本帧
        if ($written === false || ($written === 0 && feof($this->sockets[$worker]))) throw new RuntimeException('Failed writing motion worker');
        // 部分写入：裁掉已发送前缀，剩余部分留待后续 event loop 发送
        if ($written > 0) $this->outputs[$worker] = substr($this->outputs[$worker], $written);
    }

    /**
     * 从该 worker 读取数据并追加到接收缓冲。
     *
     * @param int $worker worker 编号
     */
    private function readSocket(int $worker): void
    {
        // 单次最多读 64KB，避免长时间占用主进程
        $data = @fread($this->sockets[$worker], 65536);
        // 读失败或对端关闭：worker 异常退出
        if ($data === false || ($data === '' && feof($this->sockets[$worker]))) throw new RuntimeException('Motion worker closed');
        // 追加到接收缓冲，等待 takeFrames 拆包
        $this->inputs[$worker] .= $data;
    }

    /**
     * 析构：关闭所有连接并回收由本客户端拉起的 worker 子进程。
     */
    public function __destruct()
    {
        // 关闭所有套接字
        foreach ($this->sockets as $socket) if (is_resource($socket)) @fclose($socket);
        // 终止并回收子进程
        foreach ($this->processes as $process) {
            if (!is_resource($process)) continue;
            // 仍在运行时发终止信号
            if ((proc_get_status($process)['running'] ?? false)) @proc_terminate($process);
            // 回收进程资源，避免僵尸进程
            @proc_close($process);
        }
    }
}
