<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @purpose 运动模块分布式计算-协议（v6：色度条带恒定携带，移除色度快速档标志）
 * @author yanglong
 *
 * 主进程（MotionWorkerClient）与运动子进程（MotionWorkerServer）之间的二进制帧协议。
 *
 * 帧格式：4 字节大端长度 N + body（body 长度 1..MAX_BODY_LENGTH）。
 * 请求 body：'MWR6' + 1 字节类型 + 3 字节保留 + 4 字节 seq + 类型相关载荷。
 * 响应 body：'MWS3' + 4 字节 id + 1 字节 ok + 3 字节填充 + 4 字节 count + 结果列表。
 * 所有整数均为大端（网络字节序）打包，便于跨进程稳定解析且避免文本序列化开销。
 */
final class MotionWorkerProtocol
{
    // 单帧 body 上限 16MB，防止异常长度导致内存爆炸
    public const MAX_BODY_LENGTH = 16777216;
    // 请求类型：装载参考帧
    public const LOAD_REFERENCE = 1;
    // 请求类型：宏块批量预测任务
    public const JOB_BATCH = 2;
    // 请求魔数（v6）
    private const REQUEST_MAGIC = 'MWR6';
    // 响应魔数（v3）
    private const RESPONSE_MAGIC = 'MWS3';
    // seq 字段长度（4 字节大端）
    private const SEQ_LENGTH = 4;
    // 每个 job 元数据长度：index/x/y/range 各 4 字节
    private const JOB_META_LENGTH = 16;
    // 结果标志位：携带亮度残差
    private const FLAG_HAS_LUMA_RESIDUAL = 0x01;
    // 结果标志位：携带色度残差
    private const FLAG_HAS_CHROMA_RESIDUAL = 0x02;
    private const FLAG_DCT8 = 0x04;          // 该 MB 亮度残差为 8x8DCT 域（T5）
    private const BATCH_FLAG_DCT8 = 0x01;    // batch 头标志：允许 worker 做 inter 8x8DCT 双路决策

    /**
     * 给 body 加上 4 字节大端长度前缀，形成可流式传输的帧。
     *
     * @param string $body 帧体
     * @return string 长度前缀 + 帧体
     */
    public static function frame(string $body): string
    {
        // 帧体长度
        $length = strlen($body);
        // 长度非法（空帧或超限）直接拒绝
        if ($length < 1 || $length > self::MAX_BODY_LENGTH) throw new InvalidArgumentException('Invalid motion worker frame');
        // pack('N') = 32 位无符号大端
        return pack('N', $length) . $body;
    }

    /**
     * 从接收缓冲区中取出若干个完整帧（就地消费缓冲区）。
     *
     * @param string $buffer 接收缓冲区（引用传递，函数内会裁掉已取出的字节）
     * @param int    $limit  单次最多取出的帧数，避免长时间占用事件循环
     * @return array 帧体列表
     */
    public static function takeFrames(string &$buffer, int $limit = 16): array
    {
        $frames = [];
        // 缓冲区至少有 4 字节长度前缀，且未达本次取帧上限时继续
        while (count($frames) < $limit && strlen($buffer) >= 4) {
            // 解析长度前缀
            $length = unpack('N', substr($buffer, 0, 4))[1];
            // 长度非法视为协议错误
            if ($length < 1 || $length > self::MAX_BODY_LENGTH) throw new UnexpectedValueException('Invalid motion worker length');
            // 数据尚未收全（半帧）：保留缓冲区，等待后续字节
            if (strlen($buffer) < $length + 4) break;
            // 取出一个完整帧体
            $frames[] = substr($buffer, 4, $length);
            // 从缓冲区中移除已消费的「长度前缀 + 帧体」
            $buffer = substr($buffer, 4 + $length);
        }
        return $frames;
    }

    /**
     * 构造「装载参考帧」请求帧。
     *
     * body 布局：'MWR6'(4) + type(1) + 保留(3) + seq(4) + width/height/aw/ah(各 4) + Y平面 + U平面 + V平面。
     * 参考帧平面尺寸：Y = aw*ah；U/V = (aw/2)*(ah/2)（4:2:0）。
     *
     * @param int    $seq          参考帧序号（1..0xFFFFFFFF）
     * @param int    $width        实际帧宽（像素）
     * @param int    $height       实际帧高（像素）
     * @param int    $alignedWidth 宏块对齐宽度
     * @param int    $alignedHeight 宏块对齐高度
     * @param string $refY         亮度平面
     * @param string $refU         色度 U 平面
     * @param string $refV         色度 V 平面
     * @return string 带长度前缀的完整帧
     */
    public static function loadReference(int $seq, int $width, int $height, int $alignedWidth, int $alignedHeight, string $refY, string $refU, string $refV): string
    {
        // 校验 seq 取值范围
        self::validateSeq($seq);
        // 色度平面元素数（4:2:0 各方向减半）
        $chromaLength = intdiv($alignedWidth, 2) * intdiv($alignedHeight, 2);
        // 三个平面的字节数必须与几何参数完全匹配，否则后续下标计算会越界
        if (strlen($refY) !== $alignedWidth * $alignedHeight || strlen($refU) !== $chromaLength || strlen($refV) !== $chromaLength) {
            throw new InvalidArgumentException('Invalid motion worker reference planes');
        }
        // 拼接请求体：\0\0\0 为类型字段后的保留字节
        return self::frame(self::REQUEST_MAGIC . chr(self::LOAD_REFERENCE) . "\0\0\0" . pack('N', $seq) . pack('N4', $width, $height, $alignedWidth, $alignedHeight) . $refY . $refU . $refV);
    }

    /**
     * BATCH 请求（v6）：
     * 亮度按宏块行发送连续的 16 行条带；色度条带恒定携带，
     * 布局为每宏块行 8 行 U 紧接 8 行 V（各 8*cw 字节，cw=aw/2），总长 stripCount*8*aw。
     *
     * body 布局：'MWR6'(4) + type(1) + 保留(3) + seq(4)
     *           + id/qp/count/stripOffset/stripCount/aw(各 4) + flags(1)
     *           + 亮度条带 + 色度条带 + 每个 job 16 字节元数据。
     *
     * @param int    $id           请求 id（用于响应配对）
     * @param int    $seq          参考帧序号
     * @param int    $qp           本帧量化参数
     * @param array  $jobs         job 索引 => [x, y, range]（y 为全帧绝对宏块行）
     * @param string $strips       亮度条带拼接串（stripCount 个，每条 16*aw 字节）
     * @param int    $stripOffset  首条带对应的绝对宏块行
     * @param int    $stripCount   条带数
     * @param int    $aw           宏块对齐宽度（条带跨距）
     * @param string $chromaStrips 色度条带拼接串（stripCount*8*aw 字节）
     * @param int    $flags        batch 头标志（bit0=BATCH_FLAG_DCT8 允许 inter 8x8DCT 决策；旧主进程默认 0）
     */
    public static function batch(int $id, int $seq, int $qp, array $jobs, string $strips, int $stripOffset, int $stripCount, int $aw, string $chromaStrips, int $flags = 0): string
    {
        // 校验 seq
        self::validateSeq($seq);
        // 条带几何必须自洽：亮度条带字节数 = 条带数 * 16 行 * 跨距
        if ($stripCount < 0 || $stripOffset < 0 || $aw <= 0 || strlen($strips) !== $stripCount * 16 * $aw) {
            throw new InvalidArgumentException('Invalid motion worker strips');
        }
        // 色度条带期望长度：每宏块行 8 行 U + 8 行 V，共 16 行 * (aw/2) 字节 = 8*aw
        $chromaStripsLength = $stripCount * 8 * $aw;
        if (strlen($chromaStrips) !== $chromaStripsLength) {
            throw new InvalidArgumentException('Invalid motion worker chroma strips');
        }
        // 先拼头部与两段条带（flags 为 1 字节，位于 N6 之后）
        $body = self::REQUEST_MAGIC . chr(self::JOB_BATCH) . "\0\0\0" . pack('N', $seq)
            . pack('N6', $id, $qp, count($jobs), $stripOffset, $stripCount, $aw)
            . chr($flags)
            . $strips;
        // 再拼色度条带
        $body .= $chromaStrips;
        // job 元数据批量打包（index,x,y,range），避免每 job 一次 pack 调用
        $flat = [];
        foreach ($jobs as $index => $job) {
            // 每个 job 必须提供 x/y/range 三个元素
            if (!isset($job[0], $job[1], $job[2])) throw new InvalidArgumentException('Invalid motion worker job');
            $flat[] = $index;
            $flat[] = $job[0];
            $flat[] = $job[1];
            $flat[] = $job[2];
        }
        // 一次性把全部元数据按 N* 打包追加
        if ($flat !== []) $body .= pack('N*', ...$flat);
        // 加长度前缀后返回
        return self::frame($body);
    }

    /**
     * 解析请求帧体（服务端使用）。
     *
     * @param string $body 帧体（不含长度前缀）
     * @return array 参考帧请求：[type, seq, width, height, aw, ah, refY, refU, refV]；
     *               batch 请求：[type, seq, id, qp, blocks, true, flags]
     */
    public static function decodeRequest(string $body): array
    {
        // 最小长度 12（魔数4 + 类型1 + 保留3 + seq4），并校验魔数
        if (strlen($body) < 12 || substr($body, 0, 4) !== self::REQUEST_MAGIC) throw new UnexpectedValueException('Invalid motion worker request');
        // 请求类型
        $type = ord($body[4]);
        // 参考帧序号（偏移 8，长度 4）
        $seq = unpack('N', substr($body, 8, self::SEQ_LENGTH))[1];
        // 分支一：参考帧装载
        if ($type === self::LOAD_REFERENCE) {
            // 头部长度：12 + 4 个 N（16）= 28
            if (strlen($body) < 28) throw new UnexpectedValueException('Invalid motion worker reference');
            // 解析 4 个几何参数
            $header = unpack('Nwidth/Nheight/Naw/Nah', substr($body, 12, 16));
            // 色度平面元素数
            $chromaLength = intdiv($header['aw'], 2) * intdiv($header['ah'], 2);
            // 三个平面总字节数
            $referenceLength = $header['aw'] * $header['ah'] + 2 * $chromaLength;
            // 总长度必须精确匹配，防止截断/多余数据
            if (strlen($body) !== 28 + $referenceLength) throw new UnexpectedValueException('Invalid motion worker reference length');
            // 平面数据起始偏移
            $offset = 28;
            // Y 平面
            $refY = substr($body, $offset, $header['aw'] * $header['ah']);
            // 越过 Y 平面
            $offset += $header['aw'] * $header['ah'];
            // U 平面
            $refU = substr($body, $offset, $chromaLength);
            // V 平面（紧随 U）
            $refV = substr($body, $offset + $chromaLength, $chromaLength);
            // 返回解构后的请求（与 Server 中的 list() 解构顺序一致）
            return [$type, $seq, $header['width'], $header['height'], $header['aw'], $header['ah'], $refY, $refU, $refV];
        }
        // 其余类型必须是 JOB_BATCH，且至少包含 36 字节固定头
        if ($type !== self::JOB_BATCH || strlen($body) < 36) throw new UnexpectedValueException('Invalid motion worker request type');
        // 解析 6 个 N 字段：id/qp/任务数/条带起始行/条带数/跨距
        $header = unpack('Nid/Nqp/Ncount/NstripOffset/NstripCount/Naw', substr($body, 12, 24));
        // 任务（job）个数
        $count = $header['count'];
        // 首个条带的绝对宏块行
        $stripOffset = $header['stripOffset'];
        // 条带行数
        $stripCount = $header['stripCount'];
        // 宏块对齐宽度
        $aw = $header['aw'];
        // 头部字段合法性检查
        if ($count < 0 || $stripCount < 0 || $stripOffset < 0 || $aw <= 0) throw new UnexpectedValueException('Invalid motion worker batch header');
        // 色度每行字节数（4:2:0 除以 2）
        $cw = intdiv($aw, 2);
        // 亮度条带字节数
        $stripsLength = $stripCount * 16 * $aw;
        $chromaStripsLength = $stripCount * 16 * $cw; // 每宏块行 8行U+8行V
        // batch 头标志（T5）：新格式头 37 字节（N6 后 1 字节 flags）；旧格式 36 字节，flags 默认 0（向后兼容）
        // 旧格式的期望总长度
        $legacyLength = 36 + $stripsLength + $chromaStripsLength + $count * self::JOB_META_LENGTH;
        // 实际 body 长度
        $bodyLength = strlen($body);
        // 固定头基准长度（新格式含 flags 时为 37）
        $base = 36;
        // batch 标志初值
        $flags = 0;
        // 多出 1 字节：新格式，读取 flags 并把基准后移 1 字节
        if ($bodyLength === $legacyLength + 1) {
            $flags = ord($body[36]);
            $base = 37;
        } elseif ($bodyLength !== $legacyLength) {
            // 既不是新格式也不是旧格式：长度非法
            throw new UnexpectedValueException('Invalid motion worker batch length');
        }
        // 条带只保留引用（零拷贝），块提取按 job 所在宏块行惰性切片
        $strips = $stripCount > 0 ? substr($body, $base, $stripsLength) : '';
        // 色度条带紧随亮度条带
        $chromaStrips = substr($body, $base + $stripsLength, $chromaStripsLength);
        // 解析出的宏块块数据：job index => [x, y, luma, range, cu, cv]
        $blocks = [];
        // job 元数据的读取偏移
        $offset = $base + $stripsLength + $chromaStripsLength;
        // 按条带下标缓存已切出的亮度行切片（同一宏块行的多个 job 共享）
        $stripRows = $stripCount > 0 ? [] : null;
        // 同上，色度行切片缓存
        $cStripRows = $stripCount > 0 ? [] : null;
        // 逐个解析 job
        for ($i = 0; $i < $count; $i++) {
            // 每个 job 元数据 16 字节：index/x/y/range
            $job = unpack('Nindex/Nx/Ny/Nrange', substr($body, $offset, 16));
            // job 所在宏块行相对条带起始的索引
            $stripIndex = $job['y'] - $stripOffset;
            // 必须落在条带范围内
            if ($stripIndex < 0 || $stripIndex >= $stripCount) throw new UnexpectedValueException('Motion worker job outside strips');
            // 取出该宏块行的亮度条带切片（首次访问时切片并缓存）
            $strip = $stripRows[$stripIndex] ??= substr($strips, $stripIndex * 16 * $aw, 16 * $aw);
            // 16x16 亮度块提取下沉到 worker 端（各 worker 并行）
            $luma = '';
            // 该宏块在条带内的字节列偏移（每宏块 16 像素）
            $base = $job['x'] * 16;
            // 逐行取出 16 字节，拼成 16x16 亮度块
            for ($row = 0; $row < 16; $row++) $luma .= substr($strip, $row * $aw + $base, 16);
            // 色度 U 块
            $cu = '';
            // 色度 V 块
            $cv = '';
            // 色度条带：前 8*cw 为 U 条，后 8*cw 为 V 条
            $cstrip = $cStripRows[$stripIndex] ??= substr($chromaStrips, $stripIndex * 16 * $cw, 16 * $cw);
            // 该宏块在色度条带内的字节列偏移（每宏块 8 像素）
            $cbase = $job['x'] * 8;
            // 逐行取出 8 字节 U 与 8 字节 V（每宏块行 8 行色度）
            for ($row = 0; $row < 8; $row++) {
                $cu .= substr($cstrip, $row * $cw + $cbase, 8);
                $cv .= substr($cstrip, 8 * $cw + $row * $cw + $cbase, 8);
            }
            // 记录该 job 的块数据（键为原始 job index，便于结果回填）
            $blocks[$job['index']] = [$job['x'], $job['y'], $luma, $job['range'], $cu, $cv];
            // 推进元数据偏移
            $offset += self::JOB_META_LENGTH;
        }
        // batch 请求统一返回：类型、seq、id、qp、块集合、true（batch 标志）、flags
        return [$type, $seq, $header['id'], $header['qp'], $blocks, true, $flags];
    }

    /**
     * 构造响应帧（服务端把预测结果回传主进程）。
     *
     * 每个宏块结果按需携带：固定 24 字节头（index/mvX/mvY/sad/cbpFull/flags）
     * + 可选 nz(24B) + 可选亮度残差(256*4B) + 可选色度 DC(8*4B) 与 AC(128*4B)
     * + 固定重建块（Y 256B + U 64B + V 64B）。
     *
     * @param int   $id      对应请求 id
     * @param array $results index => [mvX, mvY, sad, cbpLuma, nzCache, quantResidual, reconY, reconU, reconV, cbpChroma, chromaDc, chromaAc, ?, dct8]
     * @return string 带长度前缀的响应帧
     */
    public static function response(int $id, array $results): string
    {
        // 响应头：魔数 + id(4) + ok=1(1) + 填充(3) + count(4)
        $body = self::RESPONSE_MAGIC . pack('NCx3N', $id, 1, count($results));
        foreach ($results as $index => $result) {
            // 解构前 9 个固定字段
            [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV] = $result;
            // 色度 CBP 为可选字段（旧载荷缺省 0）
            $cbpChroma = $result[9] ?? 0;
            // 色度 DC 残差（可选）
            $chromaDc = $result[10] ?? [];
            // 色度 AC 残差（可选）
            $chromaAc = $result[11] ?? [];
            $dct8 = !empty($result[13]); // T5：8x8DCT 域标志（仅亮度残差非零时有意义）
            // 结果形状校验：nz 必须 24 项（16 亮度 + 4+4 色度），重建块尺寸固定
            if (count($nzCache) !== 24 || strlen($reconY) !== 256 || strlen($reconU) !== 64 || strlen($reconV) !== 64) throw new InvalidArgumentException('Invalid motion worker result');
            // 亮度是否携带残差（CBP 亮度位非零）
            $hasLuma = $cbpLuma > 0;
            // 色度是否携带残差
            $hasChroma = $cbpChroma > 0;
            // 第5个N为完整CBP：低4位luma，高2位chroma
            $cbpFull = ($cbpLuma & 0x0F) | (($cbpChroma & 0x03) << 4);
            // 固定 20 字节：index/运动矢量X/运动矢量Y/SAD/完整CBP
            $body .= pack('N5', $index, $mvX, $mvY, $sad, $cbpFull);
            // 结果标志位：是否带亮度残差、色度残差、8x8DCT 域
            $flags = ($hasLuma ? self::FLAG_HAS_LUMA_RESIDUAL : 0) | ($hasChroma ? self::FLAG_HAS_CHROMA_RESIDUAL : 0)
                | ($hasLuma && $dct8 ? self::FLAG_DCT8 : 0);
            if (!$hasLuma && !$hasChroma) {
                // 全零宏块：主进程不读取 nz/残差，省掉打包传输（与旧瘦身一致）
                // 仅写 1 字节 flags + 3 字节填充
                $body .= "\0\0\0\0";
            } else {
                // flags + nz(24B)；再按 flag 追加亮度/色度残差
                $body .= pack('Cx3', $flags);
                // 24 个非零计数（每个 1 字节）
                $body .= pack('C24', ...array_values($nzCache));
                // 亮度残差：16 个 4x4 块，每块 16 个系数，共 256 个 int32
                if ($hasLuma) {
                    $flat = [];
                    for ($block = 0; $block < 16; $block++) {
                        // 每块必须恰好 16 个系数
                        if (!isset($quantResidual[$block]) || count($quantResidual[$block]) !== 16) throw new InvalidArgumentException('Invalid motion worker residual');
                        foreach ($quantResidual[$block] as $value) $flat[] = $value;
                    }
                    // 一次打包 256 个大端 int32
                    $body .= pack('N256', ...$flat);
                }
                // 色度残差：DC 8 个 + AC 128 个 int32
                if ($hasChroma) {
                    // 色度 DC：Cb 4 + Cr 4（8 个）；色度 AC：Cb 4块 + Cr 4块（8*16 个）
                    if (count($chromaDc) !== 8) throw new InvalidArgumentException('Invalid chroma DC payload');
                    $body .= pack('N8', ...$chromaDc);
                    $flatAc = [];
                    for ($block = 0; $block < 8; $block++) {
                        // 每个色度 AC 块 16 个系数
                        if (!isset($chromaAc[$block]) || count($chromaAc[$block]) !== 16) throw new InvalidArgumentException('Invalid chroma AC payload');
                        foreach ($chromaAc[$block] as $value) $flatAc[] = $value;
                    }
                    // 一次打包 128 个大端 int32
                    $body .= pack('N128', ...$flatAc);
                }
            }
            // 追加重建块原始字节（Y 256 + U 64 + V 64）
            $body .= $reconY . $reconU . $reconV;
        }
        return self::frame($body);
    }

    /**
     * 构造错误响应帧（ok = 0，count 为错误文本长度，body 尾部为错误文本）。
     *
     * @param int    $id      对应请求 id
     * @param string $message 错误信息
     * @return string 带长度前缀的响应帧
     */
    public static function error(int $id, string $message): string
    {
        // ok 字节为 0，count 复用为消息字节数
        return self::frame(self::RESPONSE_MAGIC . pack('NCx3N', $id, 0, strlen($message)) . $message);
    }

    /**
     * 解析响应帧体（主进程使用）。
     *
     * @param string $body 帧体
     * @return array [id, ok, results|错误文本]
     */
    public static function decodeResponse(string $body): array
    {
        // 最小长度 16（魔数4 + id4 + ok1 + 填充3 + count4）
        if (strlen($body) < 16 || substr($body, 0, 4) !== self::RESPONSE_MAGIC) throw new UnexpectedValueException('Invalid motion worker response');
        // 解析响应头（x3 跳过 3 字节填充）
        $header = unpack('Nid/Cok/x3/Ncount', substr($body, 4, 12));
        // ok = 0：错误响应，count 为错误文本长度
        if (!$header['ok']) {
            if (strlen($body) !== 16 + $header['count']) throw new UnexpectedValueException('Invalid motion worker error length');
            // 返回 [id, false, 错误文本]
            return [$header['id'], false, substr($body, 16)];
        }
        // 结果宏块个数
        $count = $header['count'];
        // 结果集合
        $results = [];
        // 当前读取偏移（响应头之后）
        $offset = 16;
        for ($i = 0; $i < $count; $i++) {
            // 每个结果至少 24 字节固定头
            if (strlen($body) < $offset + 24) throw new UnexpectedValueException('Invalid motion worker response header');
            // 解出 5 个 N（index/mvX/mvY/sad/cbpFull）+ 1 字节 flags + 3 字节填充
            $packed = unpack('N5h/Cflags/x3', substr($body, $offset, 24));
            // 宏块下标
            $index = $packed['h1'];
            // 越过固定头
            $offset += 24;
            // 完整 CBP 以无符号 32 位解出，若超过 2^31 则转回有符号（保持与打包端一致）
            $cbpFull = $packed['h5']; if ($cbpFull >= 0x80000000) $cbpFull -= 0x100000000;
            // 低 4 位为亮度 CBP
            $cbpLuma = $cbpFull & 0x0F;
            // 高 2 位为色度 CBP
            $cbpChroma = ($cbpFull >> 4) & 0x03;
            // 是否带亮度残差
            $hasLuma = ($packed['flags'] & self::FLAG_HAS_LUMA_RESIDUAL) !== 0;
            // 是否带色度残差
            $hasChroma = ($packed['flags'] & self::FLAG_HAS_CHROMA_RESIDUAL) !== 0;
            $dct8 = ($packed['flags'] & self::FLAG_DCT8) !== 0 ? 1 : 0; // T5（旧载荷默认 0）
            // nz 缓存（24 项），默认全 0
            $nzCache = array_fill(0, 24, 0);
            // 量化残差：16 个亮度 4x4 块
            $quantResidual = [];
            // 色度 DC 系数
            $chromaDc = [];
            // 色度 AC 系数
            $chromaAc = [];
            // 有任一残差时才读取 nz 数组
            if ($hasLuma || $hasChroma) {
                if (strlen($body) < $offset + 24) throw new UnexpectedValueException('Invalid motion worker nz payload');
                // 解出 24 个单字节 nz 值
                $extra = unpack('C24z', substr($body, $offset, 24));
                for ($k = 1; $k <= 24; $k++) $nzCache[$k - 1] = $extra['z' . $k];
                $offset += 24;
            }
            // 亮度残差：1024 字节 = 256 个 int32
            if ($hasLuma) {
                if (strlen($body) < $offset + 1024) throw new UnexpectedValueException('Invalid motion worker luma residual payload');
                $extra = unpack('N256r', substr($body, $offset, 1024));
                for ($k = 0; $k < 256; $k++) {
                    $value = $extra['r' . ($k + 1)];
                    // 无符号转有符号
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    // 第 k 个系数属于第 k>>4 个块的第 k&15 个位置
                    $quantResidual[$k >> 4][$k & 15] = $value;
                }
                $offset += 1024;
            }
            // 色度残差：DC 32 字节 + AC 512 字节
            if ($hasChroma) {
                if (strlen($body) < $offset + 32 + 512) throw new UnexpectedValueException('Invalid motion worker chroma residual payload');
                // 8 个色度 DC 系数
                $dcPacked = unpack('N8d', substr($body, $offset, 32));
                for ($k = 1; $k <= 8; $k++) {
                    $value = $dcPacked['d' . $k];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $chromaDc[$k - 1] = $value;
                }
                $offset += 32;
                // 128 个色度 AC 系数
                $acPacked = unpack('N128a', substr($body, $offset, 512));
                for ($k = 0; $k < 128; $k++) {
                    $value = $acPacked['a' . ($k + 1)];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $chromaAc[$k >> 4][$k & 15] = $value;
                }
                $offset += 512;
            }
            // 重建块：Y 256 + U 64 + V 64 = 384 字节
            if (strlen($body) < $offset + 384) throw new UnexpectedValueException('Invalid motion worker recon payload');
            // 重建亮度块
            $reconY = substr($body, $offset, 256);
            // 重建 U 块
            $reconU = substr($body, $offset + 256, 64);
            // 重建 V 块
            $reconV = substr($body, $offset + 320, 64);
            $offset += 384;
            // 运动矢量与 SAD 均为有符号 32 位
            $mvX = $packed['h2']; if ($mvX >= 0x80000000) $mvX -= 0x100000000;
            $mvY = $packed['h3']; if ($mvY >= 0x80000000) $mvY -= 0x100000000;
            $sad = $packed['h4']; if ($sad >= 0x80000000) $sad -= 0x100000000;
            // 按与请求相同的宏块下标回填结果（字段顺序与 response() 的入参一致）
            $results[$index] = [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV, $cbpChroma, $chromaDc, $chromaAc, 0, $dct8];
        }
        // 全部结果解析完后必须恰好消费完 body
        if ($offset !== strlen($body)) throw new UnexpectedValueException('Invalid motion worker response trailer');
        return [$header['id'], true, $results];
    }

    /**
     * 校验参考帧序号范围：1..0xFFFFFFFF（0 保留为「无参考帧」语义）。
     *
     * @param int $seq 序号
     */
    private static function validateSeq(int $seq): void
    {
        if ($seq < 1 || $seq > 0xFFFFFFFF) throw new InvalidArgumentException('Invalid motion worker reference seq');
    }
}
