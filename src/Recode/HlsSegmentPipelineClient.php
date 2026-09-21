<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @purpose flv转hls片级并行架构-协调客户端（片 worker 池 + 顺序发布 m3u8）
 *
 * 与 HlsPipelineClient 的区别：并行粒度从"源 GOP 解码"上移到"整段转码"。
 * 主进程单遍扫描源 FLV（只索引偏移，不读大块），按 gop_interval_ms 规划片边界（不依赖
 * 源关键帧，Task7/FR-3）、按各 profile 最低 fps 抽帧，把每片的紧凑事件表下发给一个片 worker；
 * worker 自读源文件、自解码自编码，产 segment_{seq}.ts.tmp；片 1 从源 IDR 起解，
 * 其后每片导入上一片回传的 H264 解码检查点（DPB）在非 IDR 边界续解并强制编码 IDR；
 * 主进程严格按 seq 收集结果、原子改名并增量重写 index.m3u8。
 */
final class HlsSegmentPipelineClient
{
    private array $processes = [];
    /** 单个片 worker 在途（未确认）源 tag 字节软上限，超过则暂停派发，形成端到端反压 */
    private const PER_WORKER_SOFT_LIMIT = 8388608;
    /** 无任何 worker 进展的看门狗秒数（worker 崩溃另由 socket EOF 独立覆盖） */
    private const NO_PROGRESS_TIMEOUT = 120;

    public function __construct(private array $profiles, private string $outputDir, private ?int $maxFrames)
    {
    }

    public function process(string $flvFile): void
    {
        $flvFile = str_replace('/', DIRECTORY_SEPARATOR, $flvFile);
        $flvFile = realpath($flvFile) ?: $flvFile;

        $firstProfile = reset($this->profiles);
        $gopIntervalMs = max(1, (int)($firstProfile['gop_interval_ms'] ?? 2000));
        $targetFps = 0.0;
        foreach ($this->profiles as $profile) {
            $fps = (int)($profile['fps'] ?? 0);
            if ($fps > 0 && ($targetFps <= 0 || $fps < $targetFps)) $targetFps = $fps;
        }

        // 1) 单遍扫描：抓 asc/avcc 二进制、全部 AAC 音频 / AVC NALU 视频 tag 偏移索引
        $scan = $this->scanSource($flvFile);
        if ($scan['avcc'] === '' || $scan['video'] === []) {
            throw new RuntimeException('源 FLV 不含可转码的 H.264 视频帧');
        }
        $dropFrames = $targetFps > 0 && $scan['fps'] !== null && $targetFps < $scan['fps'] - 0.01;

        // 2) 抽帧选帧 + 片边界规划（抽帧算法与 HlsPipelineClient 逐字对齐）
        //    每片：startSrc=边界 IDR 源时间戳；startOut=该帧在全局输出网格上的时间戳
        $plans = $this->planSegments($scan['video'], $dropFrames, $targetFps, $gopIntervalMs);
        if ($plans['segments'] === []) throw new RuntimeException('未找到任何 IDR 关键帧，无法开始切片');
        $segments = $plans['segments'];
        $segmentCount = count($segments);
        $videoPlan = $plans['video']; // [['vi'=>int,'seg'=>int,'drop'=>bool,'outTs'=>int]]

        // 3) 事件按片分组（保持源文件顺序；音频按源时间戳归片）
        $tasks = $this->buildTasks($scan, $videoPlan, $segments);

        $workerCount = max(1, min(8, TranscodeOptions::segmentWorkers($firstProfile), $segmentCount));
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'hls-worker.php';

        // 输出目录与初始播放列表先行落盘（worker 构造也会建目录，主进程先建避免竞态）
        foreach ($this->profiles as $name => $_) {
            $dir = $this->profileDir($name);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $this->writePlaylist($name, []);
        }

        $addresses = [];
        for ($i = 0; $i < $workerCount; $i++) $addresses[] = $this->reserveAddress();
        $sockets = [];
        try {
            foreach ($addresses as [, $port]) {
                $this->startWorker([$worker, '--mode', 'segment', '--autoload', $autoload, '--port', (string)$port, '--profiles', $this->encodeOption($this->profiles), '--output', $this->outputDir]);
            }
            foreach ($addresses as [$address]) {
                $socket = $this->connect($address);
                stream_set_blocking($socket, false);
                $sockets[] = $socket;
            }

            // 4) 事件循环：贪心派发（按在途字节选最闲 worker）+ 乱序回收 + 顺序发布
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true); // 收到 FINISHED 后停止读该连接（worker 随即退出会 EOF）
            $inFlight = array_fill(0, $workerCount, 0);
            $taskWorker = [];
            $done = [];          // seq => endOutMs
            // 检查点链接（Task7/FR-3，Task8 早期回传）：$checkpoints[$n] = 片 n 末帧 DPB（base64），
            // 由 worker 在末帧【解码完成】时立即以 segmentCp 帧回传（早于编码冲刷后的 segmentDone），
            // 于是片 n 编码可与片 n+1 解码重叠（二级流水）；片 1 无需检查点。
            // 片 n+1 被派发后检查点立即出表释放内存（全程至多滞留 1 份）
            $checkpoints = [];
            $rr = 0;             // 等负载平局时轮转选 worker，避免全部片钉死同一 worker（Task8/B1）
            $nextDispatch = 1;
            $nextPublish = 1;
            $endSent = 0;
            $finishedCount = 0;
            $durations = [];
            $progressDeadline = microtime(true) + self::NO_PROGRESS_TIMEOUT;

            while (true) {
                // 派发：片 n+1 必须等片 n 的 segmentCp（末帧解码完成，非整片完成）后才可派发（链式依赖）；
                // 在软限内贪心选在途字节最少的 worker（平局轮转）；超大单片允许空闲 worker 独揽
                while ($nextDispatch <= $segmentCount) {
                    if ($nextDispatch > 1 && !isset($checkpoints[$nextDispatch - 1])) break;
                    $taskLen = $tasks[$nextDispatch]['bytes'];
                    // 等负载平局按 $rr 轮转：早期 cp 回传后多个 worker 可能同时空闲，
                    // 严格取最小索引会把所有片钉死 worker0（Task8/B1）
                    $candidate = -1;
                    $candidateLoad = -1;
                    $idle = -1;
                    for ($i = 0; $i < $workerCount; $i++) {
                        $w = ($rr + $i) % $workerCount;
                        if ($inFlight[$w] + strlen($outbound[$w]) + $taskLen <= self::PER_WORKER_SOFT_LIMIT) {
                            $load = $inFlight[$w] + strlen($outbound[$w]);
                            if ($candidate < 0 || $load < $candidateLoad) { $candidate = $w; $candidateLoad = $load; }
                        }
                        if ($idle < 0 && $inFlight[$w] === 0 && $outbound[$w] === '') $idle = $w;
                    }
                    if ($candidate < 0) {
                        if ($idle < 0) break;
                        $candidate = $idle; // 单片本身超过软限：等出一个完全空闲 worker
                    }
                    $rr = ($candidate + 1) % $workerCount;
                    $cpB64 = $nextDispatch === 1 ? '' : $checkpoints[$nextDispatch - 1];
                    $outbound[$candidate] .= HlsPipelineProtocol::frame(
                        HlsPipelineProtocol::CONTROL,
                        $nextDispatch,
                        [
                            'cmd' => 'segmentTask',
                            'seq' => $nextDispatch,
                            'file' => $flvFile,
                            'asc' => base64_encode($scan['asc']),
                            'avcc' => base64_encode($scan['avcc']),
                            'cp' => $cpB64,
                            'events' => $tasks[$nextDispatch]['events'],
                        ]
                    );
                    unset($checkpoints[$nextDispatch - 1]);
                    $inFlight[$candidate] += $taskLen;
                    $taskWorker[$nextDispatch] = $candidate;
                    $nextDispatch++;
                }
                if ($nextDispatch > $segmentCount && $endSent < $workerCount) {
                    // 全部任务已派发：给每个 worker 发 END（任务均在 END 帧之前入其连接队列）
                    for ($w = $endSent; $w < $workerCount; $w++) {
                        $outbound[$w] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $segmentCount + 1);
                    }
                    $endSent = $workerCount;
                }

                $read = [];
                foreach ($alive as $w => $isAlive) if ($isAlive) $read[] = $sockets[$w];
                $write = [];
                foreach ($outbound as $w => $buffer) if ($buffer !== '' && $alive[$w]) $write[] = $sockets[$w];
                if ($read === [] && $write === []) {
                    if ($finishedCount >= $workerCount) break;
                    throw new RuntimeException('片 worker 全部离线但任务未完成');
                }
                $except = null;
                if (@stream_select($read, $write, $except, 0,1) === false) {
                    if (microtime(true) >= $progressDeadline) {
                        throw new RuntimeException('片 worker 长时间无进展（' . self::NO_PROGRESS_TIMEOUT . 's）');
                    }
                    continue;
                }

                foreach ($write as $socket) {
                    $w = (int)array_search($socket, $sockets, true);
                    $n = @fwrite($socket, substr($outbound[$w], 0, 65536));
                    if ($n === false || ($n === 0 && feof($socket))) {
                        throw new RuntimeException('片 worker 连接意外关闭（派发中）');
                    }
                    if ($n > 0) $outbound[$w] = substr($outbound[$w], $n);
                }
                foreach ($read as $socket) {
                    $w = (int)array_search($socket, $sockets, true);
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) {
                        // END 之后 worker 回完 FINISHED 即退出：FINISHED 已在本轮入缓冲解析，EOF 属正常
                        if (!$alive[$w]) continue;
                        throw new RuntimeException('片 worker 连接意外关闭');
                    }
                    if ($chunk !== '') $inbound[$w] .= $chunk;
                }
                foreach ($inbound as $w => $buffer) {
                    foreach (HlsPipelineProtocol::take($inbound[$w], PHP_INT_MAX) as $event) {
                        if ($event['type'] === HlsPipelineProtocol::ERROR) {
                            throw new RuntimeException($event['metadata']['message'] ?? '片 worker 失败');
                        }
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) {
                            $finishedCount++;
                            $alive[$w] = false;
                            continue;
                        }
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($event['type'] !== HlsPipelineProtocol::CONTROL
                            || ($cmd !== 'segmentCp' && $cmd !== 'segmentDone')) {
                            throw new RuntimeException('片 worker 回传了未知帧');
                        }
                        $seq = (int)$event['metadata']['seq'];
                        if ($cmd === 'segmentCp') {
                            // 末帧解码完成即回传（早于编码冲刷）：入表放行片 seq+1 派发，
                            // 本片仍在途（inFlight 不释放），形成 解码(seq+1)∥编码(seq) 流水
                            $cpB64 = (string)($event['metadata']['cp'] ?? '');
                            if ($seq < $segmentCount && $cpB64 === '') {
                                throw new RuntimeException("片 {$seq} 未回传解码检查点，后续片无法续解");
                            }
                            if ($seq < $segmentCount) $checkpoints[$seq] = $cpB64;
                            $progressDeadline = microtime(true) + self::NO_PROGRESS_TIMEOUT;
                            continue;
                        }
                        // segmentDone：整片编码冲刷+落盘完成
                        $done[$seq] = (int)($event['metadata']['endOutMs'] ?? 0);
                        if (isset($taskWorker[$seq])) $inFlight[$taskWorker[$seq]] -= $tasks[$seq]['bytes'];
                        $progressDeadline = microtime(true) + self::NO_PROGRESS_TIMEOUT;
                    }
                    if (strlen($inbound[$w]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) {
                        throw new RuntimeException('协调进程响应缓冲超限');
                    }
                }

                // 严格按 seq 顺序发布：原子改名各 profile 的 .tmp，再增量重写播放列表
                while (isset($done[$nextPublish])) {
                    $seq = $nextPublish;
                    foreach ($this->profiles as $name => $_) {
                        $dir = $this->profileDir($name);
                        $tmp = "{$dir}/segment_{$seq}.ts.tmp";
                        $final = "{$dir}/segment_{$seq}.ts";
                        if (!is_file($tmp)) throw new RuntimeException("片 {$seq} 缺少产物: {$tmp}");
                        if (@rename($tmp, $final) === false) {
                            throw new RuntimeException("片 {$seq} 原子改名失败: {$tmp} -> {$final}");
                        }
                    }
                    // 时长口径：非末片取相邻边界输出网格时间差；末片取本片末帧与本片边界之差
                    if ($seq < $segmentCount) {
                        $durMs = (int)$segments[$seq + 1]['startOut'] - (int)$segments[$seq]['startOut'];
                    } else {
                        $durMs = (int)$done[$seq] - (int)$segments[$seq]['startOut'];
                    }
                    $durations[$seq] = max(0.001, round($durMs / 1000.0, 3));
                    foreach ($this->profiles as $name => $_) $this->writePlaylist($name, $durations);
                    unset($done[$seq]);
                    $nextPublish++;
                }

                if ($finishedCount >= $workerCount) break;
            }

            if ($nextPublish <= $segmentCount) throw new RuntimeException('仍有分片未完成发布');
            foreach ($sockets as $socket) @fclose($socket);
            $sockets = [];
            $this->waitWorkers();

            // 5) 收尾：ENDLIST + 多码率 master
            foreach ($this->profiles as $name => $_) $this->appendEndList($name);
            if (count($this->profiles) > 1) $this->writeMasterPlaylist();
            echo "Done! {$segmentCount} segments with {$workerCount} segment workers\n";
        } catch (Throwable $e) {
            foreach ($sockets as $socket) if (is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            foreach ($this->profiles as $name => $_) {
                foreach (glob($this->profileDir($name) . '/segment_*.ts.tmp') ?: [] as $leftover) @unlink($leftover);
            }
            throw $e;
        }
    }

    /**
     * 抽帧选帧 + 片边界规划（与 HlsPipelineClient 的抽帧判定逐字对齐）。
     *
     * Task7 修正（FR-3）：片 1 从首个源 IDR 起始（冷启动）；其后片边界不再被动等待
     * 源关键帧——保留帧的输出网格时间距本片起点达到 gop_interval_ms 即切开。
     * 非源 IDR 片起点由上一片末帧导出的 H264 解码检查点（DPB）链接续解，
     * 片 worker 在片首强制编码 IDR（见 PurePhpHlsGenerator::runSegmentTask）。
     *
     * @param array $videoTags [[off,len,ts,isKey],...]
     * @return array{segments: array<int,array{startSrc:int,startOut:int}>, video: array<int,array{vi:int,seg:int,drop:bool,outTs:int}>}
     */
    private function planSegments(array $videoTags, bool $dropFrames, float $targetFps, int $gopIntervalMs): array
    {
        $segments = [];   // seq(1-based) => [startSrc, startOut]
        $video = [];      // 输出顺序（首 IDR 起）=> [vi,seg,drop,outTs]
        $base = -1;
        $selectedFrames = 0;
        $currentSeg = 0;

        foreach ($videoTags as $vi => $tag) {
            $ts = (int)$tag[2];
            $isKey = !empty($tag[3]);
            $outTs = $ts; // 不抽帧时输出轴即源时间轴
            if ($base < 0) {
                if (!$isKey) continue; // 首 IDR 之前的帧整体丢弃
                $base = $ts;
                $selectedFrames = 1; // 首 IDR 为保留帧 #0，下一个保留帧为 #1
            } else {
                if (!$isKey && $dropFrames && ($ts - $base) * $targetFps < $selectedFrames * 1000) {
                    // 被抽帧：仍归当前片送 worker 解码维持参考链，不缩放/编码
                    if ($currentSeg > 0) $video[] = ['vi' => $vi, 'seg' => $currentSeg, 'drop' => true, 'outTs' => 0];
                    continue;
                }
                if ($dropFrames) {
                    $outTs = $base + (int)round($selectedFrames * 1000 / $targetFps);
                    $selectedFrames++;
                }
            }

            // 片边界（Task7/FR-3）：片 1 = 首个源 IDR；其后任一保留帧（不要求源 isKey）
            // 的输出网格时间距本片起点达到 gop_interval_ms 即切，非 IDR 起点靠检查点链接
            if ($currentSeg === 0) {
                if (!$isKey) continue; // 理论不可达（base<0 分支已拦），双保险
                $currentSeg = 1;
                $segments[1] = ['startSrc' => $ts, 'startOut' => $outTs];
            } elseif ($outTs - $segments[$currentSeg]['startOut'] >= $gopIntervalMs) {
                $currentSeg++;
                $segments[$currentSeg] = ['startSrc' => $ts, 'startOut' => $outTs];
            }
            $video[] = ['vi' => $vi, 'seg' => $currentSeg, 'drop' => false, 'outTs' => $outTs];
        }
        return ['segments' => $segments, 'video' => $video];
    }

    /**
     * 按片分组紧凑事件（保持源文件顺序）。
     * 音频按源时间戳归属 [startSrc, nextStartSrc)；首 IDR 之前音频丢弃。
     *
     * @return array<int,array{events:array,bytes:int}> seq(1-based)
     */
    private function buildTasks(array $scan, array $videoPlan, array $segments): array
    {
        $boundarySrc = [];
        foreach ($segments as $seq => $seg) $boundarySrc[] = $seg['startSrc'];
        $segmentCount = count($boundarySrc);

        // 视频帧序号 -> 计划
        $planByVi = [];
        foreach ($videoPlan as $item) $planByVi[$item['vi']] = $item;

        $tasks = [];
        for ($seq = 1; $seq <= $segmentCount; $seq++) {
            $tasks[$seq] = ['events' => [], 'bytes' => 0];
        }

        // 全局时间轴基点 = 首 IDR 源时间戳：片内 PES DTS/PTS 全局连续（与旧串行生成器
        // 跨片切分行为一致），ffmpeg 按片内 DTS 拼接时不会在边界出现重复 DTS
        $baseSrc = (int)$segments[1]['startSrc'];
        $records = $scan['order'];
        foreach ($records as $rec) {
            if ($rec[0] === 1) {
                // 视频 NALU
                $vi = (int)$rec[1];
                if (!isset($planByVi[$vi])) continue;
                $item = $planByVi[$vi];
                if (!empty($item['drop'])) {
                    $ev = [1, (int)$rec[2], (int)$rec[3], 0, 1];
                } else {
                    // 保留帧下发全局网格时间戳（片 worker 不再按片归零）
                    $ev = [1, (int)$rec[2], (int)$rec[3], (int)$item['outTs'], 0];
                }
                $seq = (int)$item['seg'];
                $tasks[$seq]['events'][] = $ev;
                $tasks[$seq]['bytes'] += (int)$rec[3];
            } else {
                // 音频 AAC raw：按源时间戳二分归片，时间戳取全局相对轴
                $ts = (int)$rec[4];
                if ($ts < $boundarySrc[0]) continue;
                $seg = $segmentCount;
                for ($i = 1; $i < $segmentCount; $i++) {
                    if ($ts < $boundarySrc[$i]) { $seg = $i; break; }
                }
                $relMs = $ts - $baseSrc;
                $tasks[$seg]['events'][] = [0, (int)$rec[2], (int)$rec[3], $relMs];
                $tasks[$seg]['bytes'] += (int)$rec[3];
            }
        }
        return $tasks;
    }

    /**
     * 单遍扫描源 FLV（记录绝对偏移，worker 再按偏移随机读）。
     *
     * @return array{asc:string,avcc:string,fps:?float,video:array<int,array{0:int,1:int,2:int,3:int}>,
     *               order:array<int,array{0:int,1:int,2:int,3:int,4?:int}>}
     *         order 元素：视频 [1, vi, tagOffset, tagLen]；音频 [0, -1, tagOffset, tagLen, ts]
     */
    private function scanSource(string $flvFile): array
    {
        $asc = '';
        $avcc = '';
        $video = [];
        $order = [];
        $handle = @fopen($flvFile, 'rb');
        if ($handle === false) throw new RuntimeException("无法打开 FLV 文件: {$flvFile}");
        try {
            $header = $this->readExact($handle, 9);
            if (substr($header, 0, 3) !== 'FLV') throw new RuntimeException('不是有效的 FLV 文件');
            $headerSize = unpack('N', substr($header, 5, 4))[1];
            if ($headerSize < 9) throw new RuntimeException('FLV Header 长度无效');
            if ($headerSize > 9) $this->readExact($handle, $headerSize - 9);
            $this->readExact($handle, 4);

            $naluCount = 0;
            $firstTs = null;
            $lastTs = null;
            while (!feof($handle)) {
                $tagOffset = ftell($handle);
                $tagHeader = fread($handle, 11);
                if ($tagHeader === false) throw new RuntimeException('读取 FLV Tag Header 失败');
                if ($tagHeader === '') break;
                if (strlen($tagHeader) !== 11) throw new RuntimeException('FLV Tag Header 不完整');
                $tagType = ord($tagHeader[0]);
                $dataSize = unpack('N', "\0" . substr($tagHeader, 1, 3))[1];
                if ($dataSize > HlsPipelineProtocol::MAX_FRAME_LENGTH) {
                    throw new RuntimeException("FLV Tag 数据过大: {$dataSize}");
                }
                $timestamp = unpack('N', $tagHeader[7] . substr($tagHeader, 4, 3))[1];
                $body = $this->readExact($handle, $dataSize);
                $this->readExact($handle, 4); // PreviousTagSize
                $tagLen = 11 + $dataSize;

                if ($tagType === 9 && strlen($body) >= 2) {
                    $packetType = ord($body[1]);
                    if ($packetType === 0 && $avcc === '') {
                        // AVCDecoderConfigurationRecord：剥掉 frameType/codecId+packetType+3B cts（共 5B）
                        $avcc = substr($body, 5);
                    } elseif ($packetType === 1) {
                        if ($this->maxFrames !== null && $naluCount >= $this->maxFrames) break;
                        $isKey = ((ord($body[0]) >> 4) === 1) && $this->containsIdrNal($body);
                        $vi = count($video);
                        $video[] = [$tagOffset, $tagLen, $timestamp, $isKey ? 1 : 0];
                        $order[] = [1, $vi, $tagOffset, $tagLen];
                        $firstTs ??= $timestamp;
                        $lastTs = $timestamp;
                        $naluCount++;
                    }
                    // packetType=2（EOS）等：不下发给片 worker
                } elseif ($tagType === 8 && strlen($body) >= 2) {
                    $soundFormat = (ord($body[0]) >> 4) & 0x0F;
                    if ($soundFormat === 10) {
                        $aacPacketType = ord($body[1]);
                        if ($aacPacketType === 0 && $asc === '') {
                            $asc = substr($body, 2);
                        } elseif ($aacPacketType === 1) {
                            $order[] = [0, -1, $tagOffset, $tagLen, $timestamp];
                        }
                    }
                }
            }
            $fps = $naluCount >= 2 && $lastTs > $firstTs
                ? ($naluCount - 1) * 1000 / ($lastTs - $firstTs)
                : null;
        } finally {
            fclose($handle);
        }
        return ['asc' => $asc, 'avcc' => $avcc, 'fps' => $fps, 'video' => $video, 'order' => $order];
    }

    /** 扫描AVCC视频包（跳过5字节FLV/AVC头），判断是否包含IDR NAL（type=5） */
    private function containsIdrNal(string $body): bool
    {
        $total = strlen($body);
        $off = 5;
        while ($off + 4 <= $total) {
            $length = unpack('N', substr($body, $off, 4))[1];
            $off += 4;
            if ($length <= 0 || $off + $length > $total) break;
            if ((ord($body[$off]) & 0x1f) === 5) return true;
            $off += $length;
        }
        return false;
    }

    private function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') throw new RuntimeException('FLV 文件数据不完整');
            $data .= $chunk;
        }
        return $data;
    }

    /** 与 PurePhpHlsGenerator::profileDir 同构：单 profile（键 ''）落根部，多 profile 落子目录 */
    private function profileDir(string $profile): string
    {
        $single = count($this->profiles) === 1 && array_key_first($this->profiles) === '';
        return $single ? $this->outputDir : "{$this->outputDir}/{$profile}";
    }

    /** 全量重写某 profile 的 index.m3u8（格式与 generator::updatePlaylist 一致，原子写） */
    private function writePlaylist(string $profile, array $durations): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        $maxDur = 3;
        foreach ($durations as $d) $maxDur = max($maxDur, (int)ceil($d));
        $lines[] = "#EXT-X-TARGETDURATION:{$maxDur}";
        $lines[] = '#EXT-X-MEDIA-SEQUENCE:1';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        foreach ($durations as $seq => $d) {
            $lines[] = '#EXTINF:' . number_format($d, 3, '.', '') . ',';
            $lines[] = "segment_{$seq}.ts";
        }
        $path = $this->profileDir($profile) . '/index.m3u8';
        $tmp = $path . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        rename($tmp, $path);
    }

    private function appendEndList(string $profile): void
    {
        $path = $this->profileDir($profile) . '/index.m3u8';
        if (!is_file($path)) return;
        $buf = rtrim((string)file_get_contents($path)) . "\n";
        if (strpos($buf, '#EXT-X-ENDLIST') === false) {
            file_put_contents($path, $buf . "#EXT-X-ENDLIST\n");
        }
    }

    /** 多码率 master.m3u8（格式与 PurePhpHlsGenerator::generateMasterPlaylist 一致） */
    private function writeMasterPlaylist(): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6'];
        $sorted = $this->profiles;
        uasort($sorted, static fn($a, $b) => $b['bitrate'] <=> $a['bitrate']);
        foreach ($sorted as $name => $cfg) {
            $audioBr = $cfg['audioBitrate'] ?? 128000;
            $bandwidth = $cfg['bitrate'] + $audioBr;
            $res = "{$cfg['width']}x{$cfg['height']}";
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="avc1.64001F,mp4a.40.2"',
                $bandwidth,
                $res
            );
            $lines[] = "{$name}/index.m3u8";
        }
        file_put_contents("{$this->outputDir}/master.m3u8", implode("\n", $lines) . "\n");
    }

    private function startWorker(array $arguments): void
    {
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')], $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($process)) throw new RuntimeException('无法启动 HLS 片 worker');
        $this->processes[] = $process;
    }

    private function waitWorkers(): void
    {
        $error = null;
        foreach ($this->processes as $key => $process) {
            if (!is_resource($process)) { unset($this->processes[$key]); continue; }
            $deadline = microtime(true) + 30;
            do { $status = proc_get_status($process); if (!$status['running']) break; usleep(1); } while (microtime(true) < $deadline);
            $timedOut = $status['running'];
            if ($timedOut) @proc_terminate($process);
            $exit = proc_close($process); unset($this->processes[$key]);
            if ($error === null && $timedOut) $error = new RuntimeException('HLS 片 worker 结束超时');
            elseif ($error === null && $exit !== 0 && $exit !== -1) $error = new RuntimeException("HLS 片 worker 异常退出: {$exit}");
        }
        if ($error !== null) throw $error;
    }

    private function terminateWorkers(): void
    {
        foreach ($this->processes as $key => $process) {
            if (!is_resource($process)) { unset($this->processes[$key]); continue; }
            $status = @proc_get_status($process);
            if ($status !== false && $status['running']) @proc_terminate($process);
            @proc_close($process); unset($this->processes[$key]);
        }
    }

    private function reserveAddress(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new RuntimeException("无法分配 loopback 端口: {$error}");
        $name = stream_socket_get_name($server, false); fclose($server);
        $port = (int)substr(strrchr($name, ':'), 1);
        return ["tcp://127.0.0.1:{$port}", $port];
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15;
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接片 worker: {$error} ({$errno})");
    }

    private function locateAutoload(): string
    {
        $reflection = new ReflectionClass(ClassLoader::class);
        $path = dirname($reflection->getFileName(), 2) . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($path)) throw new RuntimeException('无法定位宿主 Composer autoload.php');
        return $path;
    }

    private function encodeOption(array $value): string
    {
        return base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
