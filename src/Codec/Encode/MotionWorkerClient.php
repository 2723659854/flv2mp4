<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Recode\CpuInfo;

/**
 * @purpose 运动模块分布式计算-客户端
 * @author yanglong
 */
final class MotionWorkerClient
{
    private array $sockets = [];
    private array $inputs = [];
    private array $outputs = [];
    private array $processes = [];
    private array $lastReference = [];
    private array $workerSeq = [];
    private array $workerPorts = [];
    private int $id = 1;
    private int $refSeq = 0;
    /** 当前在途批次：['ids'=>worker=>requestId, 'total'=>job 总数]，collect 后清空 */
    private ?array $pending = null;

    /**
     * @param int $port 固定端口基址（0=自动分配）
     * @param int $workers motion 子进程数（0=按 CPU 核数自适应，最多 4）
     * @param array $motionOptions 下发 motion 子进程的编码选项
     *        （early_skip/subpel_sad_mul/mvp_seed/adaptive_skip），禁止子进程读环境变量
     */
    public function __construct(private int $port = 0, private int $workers = 0, private array $motionOptions = [])
    {
        $this->workers = $workers > 0 ? $workers : max(1, min(4, CpuInfo::cores()));
        if ($this->port !== 0) {
            for ($worker = 0; $worker < $this->workers; $worker++) $this->workerPorts[$worker] = $this->port + $worker;
        }
    }

    /**
     * 异步派发一帧：仅写入 socket（非阻塞尽力刷新），不等结果。
     * 帧级双缓冲时主进程随后执行上一帧的 CAVLC，worker 并行计算本帧。
     * 必须先 collect() 上一帧后才能再次 dispatch()。
     *
     * @param array  $jobs 光栅顺序连续键 0..mbWidth*mbHeight-1 => [x, y, range]
     * @param string $seedMap 前一帧 MV 地图（MotionWorkerProtocol::encodeMvMap 产物），null/空串=无种子
     * @param int    $seedW 地图宏块宽（0=无地图）
     * @param int    $seedH 地图宏块高（0=无地图）
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
        int $mbWidth,
        int $mbHeight,
        array $jobs,
        ?string $seedMap = null,
        int $seedW = 0,
        int $seedH = 0,
        int $tier1BlockSad = 0
    ): void {
        if ($this->pending !== null) throw new RuntimeException('Motion worker batch already in flight');
        $this->connectAll();
        // 参考帧按内容分配单调序号：内容不变（如纯静态画面）则复用同一序号，
        // worker 端已持有该参考帧时跳过整帧重传；字符串 === 先比长度再 memcmp，
        // 不同内容通常首字节即返回，比每帧 SHA256 便宜且无碰撞风险。
        if ($this->lastReference !== []
            && $this->lastReference[0] === $refY
            && $this->lastReference[1] === $refU
            && $this->lastReference[2] === $refV) {
            $seq = $this->refSeq;
        } else {
            $seq = ++$this->refSeq;
            $this->lastReference = [$refY, $refU, $refV];
        }

        $total = count($jobs);
        if ($total !== $mbWidth * $mbHeight) throw new RuntimeException('Motion worker jobs must cover every macroblock');
        // v5：种子地图全帧下发（每个分片只取其行区间，地图仅约 4B/宏块，全量重复发送开销可忽略）
        $seedMap ??= '';
        if ($seedMap === '') { $seedW = 0; $seedH = 0; }
        $ids = [];
        $referenceFrame = null;
        // 按宏块行连续分片：worker w 取连续键区间（=连续宏块行），
        // 每 worker 只发送自己行区间的条带，整帧条带恰好发送一次。
        for ($worker = 0; $worker < $this->workers; $worker++) {
            $kStart = (int)floor($total * $worker / $this->workers);
            $kEnd = (int)floor($total * ($worker + 1) / $this->workers);
            if ($kStart === $kEnd) continue;
            $chunk = [];
            for ($k = $kStart; $k < $kEnd; $k++) $chunk[$k] = $jobs[$k];
            $firstY = intdiv($kStart, $mbWidth);
            $lastY = intdiv($kEnd - 1, $mbWidth);
            $stripOffset = $firstY;
            $stripCount = $lastY - $firstY + 1;
            $strips = substr($curY, $stripOffset * 16 * $aw, $stripCount * 16 * $aw);

            $id = $this->id++;
            $ids[$worker] = $id;
            if (($this->workerSeq[$worker] ?? null) !== $seq) {
                $referenceFrame ??= MotionWorkerProtocol::loadReference($seq, $width, $height, $aw, $ah, $refY, $refU, $refV);
                $this->outputs[$worker] .= $referenceFrame;
                $this->workerSeq[$worker] = $seq;
            }
            $this->outputs[$worker] .= MotionWorkerProtocol::batch($id, $seq, $qp, $chunk, $strips, $stripOffset, $stripCount, $aw, $seedMap, $seedW, $seedH, $tier1BlockSad);
        }
        $this->pending = ['ids' => $ids, 'total' => $total];
        // 尽力立即把请求刷出去，剩余部分由 collect 的 event loop 排空
        foreach (array_keys($ids) as $worker) {
            if ($this->outputs[$worker] !== '') $this->writeSocket($worker);
        }
    }

    /**
     * 等待并合并在途批次结果（阻塞至全部 worker 响应）。
     */
    public function collect(): array
    {
        if ($this->pending === null) throw new RuntimeException('No motion worker batch in flight');
        $ids = $this->pending['ids'];
        $total = $this->pending['total'];
        $result = [];
        $deadline = microtime(true) + 30;
        // Task8/B1：自适应轮询（与 MotionWorkerServer 同理）。strip 结果是分片到达的，固定
        // 2ms 超时会让每次"这轮还没到齐"的空等付出 2ms，实测深度并行时父进程大部分时间在睡、
        // 吞吐反降。空轮 1µs 起指数退避至 1ms，任何就绪立即恢复 1µs；批次间隙快速进入长睡，
        // 活跃期延迟维持亚毫秒级
        $waitUs = 1;
        while ($ids !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('Timed out motion worker batch');
            $read = [];
            $write = [];
            foreach (array_keys($ids) as $worker) {
                $read[] = $this->sockets[$worker];
                if ($this->outputs[$worker] !== '') $write[] = $this->sockets[$worker];
            }
            $except = null;
            //$ready = @stream_select($read, $write, $except, 0, $waitUs);
            $ready = @stream_select($read, $write, $except, 0, 1);
            if ($ready === false) throw new RuntimeException('Failed waiting for motion worker');
            if ($ready > 0) $waitUs = 1;
            else $waitUs = min(1000, $waitUs * 2);
            foreach ($write as $socket) $this->writeSocket($this->workerFor($socket));
            foreach ($read as $socket) {
                $worker = $this->workerFor($socket);
                $this->readSocket($worker);
                foreach (MotionWorkerProtocol::takeFrames($this->inputs[$worker], 16) as $body) {
                    [$responseId, $ok, $part] = MotionWorkerProtocol::decodeResponse($body);
                    if ($responseId !== $ids[$worker]) throw new RuntimeException("Unexpected motion worker response {$responseId}");
                    if (!$ok) throw new RuntimeException('Motion worker failed: ' . $part);
                    foreach ($part as $key => $value) $result[$key] = $value;
                    unset($ids[$worker]);
                }
            }
        }
        $this->pending = null;
        if (count($result) !== $total) throw new RuntimeException('Incomplete motion worker batch');
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
        int $mbWidth,
        int $mbHeight,
        array $jobs,
        ?string $seedMap = null,
        int $seedW = 0,
        int $seedH = 0,
        int $tier1BlockSad = 0
    ): array {
        $this->dispatch($width, $height, $aw, $ah, $qp, $refY, $refU, $refV, $curY, $mbWidth, $mbHeight, $jobs, $seedMap, $seedW, $seedH, $tier1BlockSad);
        return $this->collect();
    }

    private function allocatePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new RuntimeException("Unable to reserve motion worker port: {$error} ({$errno})");
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr(strrchr($name, ':'), 1);
    }

    public function connectAll(): void
    {
        // 第一阶段：一次性拉起全部子进程（PHP 冷启动并发进行，避免逐个等待）
        $pending = [];
        $entry = dirname(__DIR__, 3) . '/bin/motion-worker.php';
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        for ($worker = 0; $worker < $this->workers; $worker++) {
            if (isset($this->sockets[$worker]) && is_resource($this->sockets[$worker])) continue;
            $lock = null;
            if (!isset($this->workerPorts[$worker])) {
                $lock = fopen(sys_get_temp_dir() . '/flv2mp4-motion-worker-port.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock motion worker port allocation');
                $this->workerPorts[$worker] = $this->allocatePort();
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $port = $this->workerPorts[$worker];
            $socket = $this->port === 0 ? false : @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
            if ($socket === false) {
                // 多进程转码时多个 PHP 冷启动并发，2 秒窗口会偶发连接超时；放宽到 15 秒
                // 编码选项以启动参数显式下发，motion 子进程内不得读取环境变量
                $mul = (float)($this->motionOptions['subpel_sad_mul'] ?? 4.0);
                if ($mul <= 0) $mul = 4.0;
                $spawnArgs = [
                    PHP_BINARY, $entry, '--owned', "--port={$port}", "--autoload={$autoload}",
                    '--early-skip=' . (empty($this->motionOptions['early_skip']) ? '0' : '1'),
                    '--subpel-mul=' . $mul,
                ];
                $process = @proc_open($spawnArgs, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) throw new RuntimeException('Unable to start motion worker');
                $this->processes[] = $process;
                $pending[$worker] = $port;
            } else {
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                unset($this->workerSeq[$worker]);
            }
        }

        // 第二阶段：并发轮询，等待所有子进程监听就绪
        $deadline = microtime(true) + 15;
        while ($pending !== []) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Unable to connect motion workers (timeout): ' . implode(',', $pending));
            foreach ($pending as $worker => $port) {
                $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
                if ($socket === false) continue;
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                unset($this->workerSeq[$worker], $pending[$worker]);
            }
            if ($pending !== []) usleep(1);
        }
    }

    private function workerFor($socket): int
    {
        foreach ($this->sockets as $worker => $candidate) if ($candidate === $socket) return $worker;
        throw new RuntimeException('Unknown motion worker socket');
    }

    private function writeSocket(int $worker): void
    {
        $written = @fwrite($this->sockets[$worker], $this->outputs[$worker]);
        if ($written === false || ($written === 0 && feof($this->sockets[$worker]))) throw new RuntimeException('Failed writing motion worker');
        if ($written > 0) $this->outputs[$worker] = substr($this->outputs[$worker], $written);
    }

    private function readSocket(int $worker): void
    {
        $data = @fread($this->sockets[$worker], 65536);
        if ($data === false || ($data === '' && feof($this->sockets[$worker]))) throw new RuntimeException('Motion worker closed');
        $this->inputs[$worker] .= $data;
    }

    public function __destruct()
    {
        foreach ($this->sockets as $socket) if (is_resource($socket)) @fclose($socket);
        foreach ($this->processes as $process) {
            if (!is_resource($process)) continue;
            if ((proc_get_status($process)['running'] ?? false)) @proc_terminate($process);
            @proc_close($process);
        }
    }
}
