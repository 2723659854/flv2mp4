<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;

/**
 * @purpose 解码波前（Task4 FR-5）：同一源 GOP 内按帧区间切分给多个解码 worker。
 * 首区间从源 IDR 起解；后续区间启动前，调度器把前段 worker 在边界帧导出的
 * 检查点（H264Decoder::exportCheckpoint 的 serialize 负载）转发给归属 worker。
 * 本类只做区间规划/帧路由/检查点收转的纯状态逻辑，不含 socket IO。
 */
final class WavefrontDispatch
{
    /** 区间最短视频帧数：低于该粒度的 GOP 不二次切分 */
    public const MIN_RANGE_FRAMES = 12;

    /**
     * @param int $totalFrames 源视频帧（AVCC packetType=1）总数
     * @param int[] $gopStarts 各源 IDR 的 0 基视频帧序号（通常含 0）
     * @param int $workers 解码 worker 数
     * @return array 调度状态（ranges/frameMap/on）
     */
    public static function begin(int $totalFrames, array $gopStarts, int $workers): array
    {
        $workers = max(1, $workers);
        $starts = $gopStarts;
        sort($starts);
        if ($starts === [] || $starts[0] !== 0) array_unshift($starts, 0);
        $starts[] = $totalFrames;

        $ranges = [];
        $frameMap = [];
        $id = 0;
        for ($g = 0, $gopCount = count($starts) - 1; $g < $gopCount; $g++) {
            $start = $starts[$g];
            $end = $starts[$g + 1]; // 排他边界
            $len = $end - $start;
            if ($len <= 0) continue;
            // 区间数 = min(worker 数, floor(帧数/12))，至少 1 个；帧数尽量均分
            $n = min($workers, max(1, intdiv($len, self::MIN_RANGE_FRAMES)));
            $baseWorker = $g % $workers;
            $q = intdiv($len, $n);
            $rem = $len % $n;
            $pos = $start;
            for ($j = 0; $j < $n; $j++) {
                $rlen = $q + ($j < $rem ? 1 : 0);
                $rid = $id++;
                $ranges[$rid] = [
                    'id' => $rid,
                    'gop' => $g,
                    'worker' => ($baseWorker + $j) % $workers,
                    'needCp' => $j > 0,
                    'start' => $pos,
                    'end' => $pos + $rlen - 1,
                    'released' => $j === 0, // 首区间（IDR 起）无需检查点，立即可派发
                    'buffer' => '',
                ];
                for ($f = $pos; $f < $pos + $rlen; $f++) $frameMap[$f] = $rid;
                $pos += $rlen;
            }
        }
        return ['ranges' => $ranges, 'map' => $frameMap];
    }

    /**
     * 路由一个视频帧：返回 ['w'=>归属 worker, 'hold'=>是否需缓冲至检查点到达]。
     * 边界帧（归前段）会在 $meta 上打 cpAfter=后段区间 id，worker 解码后回传检查点。
     */
    public static function routeVideo(array &$st, int $vIdx, array &$meta): array
    {
        if (!isset($st['map'][$vIdx])) {
            throw new RuntimeException("波前调度：视频帧 {$vIdx} 不在任何区间内");
        }
        $rid = $st['map'][$vIdx];
        $r = &$st['ranges'][$rid];
        if ($vIdx === $r['end']
            && isset($st['ranges'][$rid + 1])
            && $st['ranges'][$rid + 1]['gop'] === $r['gop']) {
            $meta['cpAfter'] = $rid + 1;
        }
        $hold = $r['needCp'] && !$r['released'];
        return ['w' => $r['worker'], 'hold' => $hold, 'rid' => $rid];
    }

    /** 待检查点帧的缓冲字节（计入调度器高水位反压） */
    public static function pendingBytes(array $st): int
    {
        $total = 0;
        foreach ($st['ranges'] as $r) $total += strlen($r['buffer']);
        return $total;
    }

    /**
     * 收到前段 worker 回传的检查点：释放后段区间，返回应写入归属 worker 的
     * 控制帧 + 缓冲帧序列（区间自成体系：IDR 清 DPB、检查点整体覆盖 DPB，
     * 故追加到该 worker 当前待写字节之后即可，输出侧仍按全局 sequence 重排）。
     */
    public static function release(array &$st, int $rid, string $cpPayload): array
    {
        if (!isset($st['ranges'][$rid])) {
            throw new RuntimeException("波前调度：收到未知区间 {$rid} 的检查点");
        }
        $r = &$st['ranges'][$rid];
        if (!$r['needCp']) {
            throw new RuntimeException("波前调度：区间 {$rid} 不需要检查点");
        }
        if ($r['released']) {
            throw new RuntimeException("波前调度：区间 {$rid} 检查点重复到达");
        }
        $wire = HlsPipelineProtocol::frame(
            HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'checkpoint', 'range' => $rid], $cpPayload
        ) . $r['buffer'];
        $w = $r['worker'];
        $r['buffer'] = '';
        $r['released'] = true;
        return ['w' => $w, 'wire' => $wire];
    }

    /** 源全部读完后等待检查点期间调用：是否所有需要检查点的区间都已释放 */
    public static function allReleased(array $st): bool
    {
        foreach ($st['ranges'] as $r) {
            if ($r['needCp'] && !$r['released']) return false;
        }
        return true;
    }

    /** 全部帧派发完毕后调用：检查点缺失会导致后段 worker 悬挂，必须显式失败 */
    public static function assertComplete(array $st): void
    {
        foreach ($st['ranges'] as $r) {
            if ($r['needCp'] && !$r['released']) {
                throw new RuntimeException("波前调度：区间 {$r['id']}（GOP {$r['gop']}）缺失前段检查点");
            }
        }
    }
}
