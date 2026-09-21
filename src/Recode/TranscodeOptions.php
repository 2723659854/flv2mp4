<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

/**
 * @purpose 转码配置归一化：所有快速/并行特性开关的唯一入口
 *
 * 纪律：禁止以环境变量读取功能开关；入口类构造第二参数 bool 为总开关，
 * 细项全部经由 config 数组显式控制，并随 worker --config/--profiles 透传。
 */
final class TranscodeOptions
{
    /**
     * 归一化配置（幂等）。
     *
     * worker 子进程内部以 $fast=false 再次构造入口类时，父进程下发的配置
     * 已包含全部键，"+=" 不会覆盖既有值，故快速选项在子进程中保持生效。
     *
     * @param array $config 用户配置
     * @param bool $fast 总开关：true=快速重编码，false=原始串行路径
     */
    public static function normalize(array $config, bool $fast): array
    {
        $config += [
            // —— 随总开关的新快速项 ——
            'fast_drop' => $fast,          // 丢弃帧跳过去块与输出平面组装
            'adaptive_skip' => $fast,     // 静态场景自适应放宽 Tier1 skip
            'mvp_seed' => $fast,          // 运动估计时间 MVP 种子
            'decode_wavefront' => $fast,  // 解码 GOP 内波前检查点交接
            'segment_pool' => $fast,      // HLS 片级 worker 池（按 gop_interval_ms 整片并行转码）
            // —— 历史即开项：默认恒开（保证 false 路径逐字节复现旧基线），仅支持显式关闭 ——
            'frame_pipeline' => true,     // 帧级双缓冲
            'early_skip' => true,         // Tier1/Tier2 快速跳过
            'subpel_sad_mul' => 4.0,      // Tier2 亚像素跳过倍数
            // —— 并行调度 ——
            'segment_workers' => null,    // null=按核数自适应（保留 2 核余量，1~8）
            'gop_interval_ms' => 2000,    // 输出强制 IDR 间隔（HLS 片/FLV/MP4 段边界）
            // —— adaptive_skip 细项（仅 adaptive_skip=true 时生效）——
            'adaptive_hit_ratio' => 0.9,  // 上一 P 帧自然 skip 占比阈值，达到后放宽下一帧
            'adaptive_block_sad' => 0,    // 每 4x4 块经验绝对 SAD 限额，0=按 qp 自动（钳 0..4080）
        ];

        $config['fast_drop'] = (bool)$config['fast_drop'];
        $config['adaptive_skip'] = (bool)$config['adaptive_skip'];
        $config['mvp_seed'] = (bool)$config['mvp_seed'];
        $config['decode_wavefront'] = (bool)$config['decode_wavefront'];
        $config['segment_pool'] = (bool)$config['segment_pool'];
        $config['frame_pipeline'] = (bool)$config['frame_pipeline'];
        $config['early_skip'] = (bool)$config['early_skip'];

        $mul = (float)$config['subpel_sad_mul'];
        $config['subpel_sad_mul'] = $mul > 0 ? $mul : 4.0;
        $config['gop_interval_ms'] = max(1, (int)$config['gop_interval_ms']);

        $hitRatio = (float)$config['adaptive_hit_ratio'];
        $config['adaptive_hit_ratio'] = max(0.0, min(1.0, $hitRatio));
        $blockSad = (int)$config['adaptive_block_sad'];
        $config['adaptive_block_sad'] = max(0, min(4080, $blockSad));

        if (empty($config['decode_workers'])) $config['decode_workers'] = CpuInfo::defaultWorkers(8);
        if (empty($config['motionWorkers'])) $config['motionWorkers'] = CpuInfo::defaultWorkers(8);

        if ($config['segment_workers'] !== null) {
            $config['segment_workers'] = max(1, min(8, (int)$config['segment_workers']));
        }

        return $config;
    }

    /** 片/段编码 worker 池大小：显式配置优先，否则核数自适应（保留 2 核余量） */
    public static function segmentWorkers(array $config): int
    {
        $n = $config['segment_workers'] ?? null;
        if ($n !== null) return max(1, min(8, (int)$n));
        return CpuInfo::defaultWorkers(8, 2);
    }

    /** 将编码相关配置应用到 H264Encoder（含下发 motion 子进程的选项） */
    public static function applyEncoder(H264Encoder $encoder, array $config): void
    {
        $mul = (float)($config['subpel_sad_mul'] ?? 4.0);
        if ($mul <= 0) $mul = 4.0;

        $encoder->framePipeline = (bool)($config['frame_pipeline'] ?? true);
        $encoder->earlySkip = (bool)($config['early_skip'] ?? true);
        $encoder->subpelSadMul = $mul;
        $encoder->mvpSeed = (bool)($config['mvp_seed'] ?? false);
        $encoder->adaptiveSkip = (bool)($config['adaptive_skip'] ?? false);
        $encoder->adaptiveHitRatio = (float)($config['adaptive_hit_ratio'] ?? 0.9);
        $encoder->adaptiveBlockSad = (int)($config['adaptive_block_sad'] ?? 0);
        // worker 进程不自行判定自适应（限额按帧随 motion v7 协议下发），motionOptions 无需 adaptive_*
        $encoder->motionOptions = [
            'early_skip' => (bool)($config['early_skip'] ?? true),
            'subpel_sad_mul' => $mul,
            'mvp_seed' => (bool)($config['mvp_seed'] ?? false),
        ];
    }
}
