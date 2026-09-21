<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 分片管理器
 * @author yanglong
 */
trait SliceEncodeTrait
{
    /**
     * 帧级双缓冲状态（深度 1）：
     * - $pipeFlight：已 startFrame、worker 可能正在计算（P）或待编码（I）的当前帧 ctx
     * - $pipePending：上一帧已 settle（结果收齐/IDR 已提前编码），等待 finishFrame 输出
     * 时序：start(N+1) 收齐 N 的结果并立即拼出参考帧、异步派发 N+1，
     * 随后 finishFrame() 才对 N 做串行 CAVLC —— worker 计算 N+1 与主进程 CAVLC N 重叠。
     */
    private ?array $pipeFlight = null;
    private ?array $pipePending = null;
    /** 流水线模式下 recon 已在 settle 阶段按 worker 结果整帧拼好，CAVLC 内逐 MB 拷贝跳过 */
    private bool $reconPreassembled = false;
    /** 当前待编码帧的 recon 是否已由 installReconFromResults 预拼（区别于非流水线 P 帧） */
    private bool $reconPrefetched = false;
    /**
     * 上一 P 帧的 MV 地图（mvp_seed 时间种子）：['w'=>mbWidth,'h'=>mbHeight,'mvs'=>[[mvX,mvY],...]]
     * 光栅顺序；IDR 后/帧尺寸变化时置 null（下一 P 帧自动从 (0,0) 起搜）。
     */
    private ?array $prevFrameMvs = null;
    /**
     * 上一已 settle P 帧的 skip 统计（v7 自适应）：
     * ['w'=>mbWidth,'h'=>mbHeight,'nat'=>自然 skip MB 数,'forced'=>Tier1 强制命中数,'total'=>MB 总数]；
     * 自然 skip = 完整搜索/DCT 后 cbp=0 且 MV=0 且非 Tier1 命中（nat 必为"完整路径 MB"子集）。
     * 武装/保持信号 = nat / max(1, total-forced)：只在真正走过完整路径的 MB 上评估，
     *   未武装帧 forced≈严格死区命中（≈0），等价于整帧自然 skip 率（≥ratio 才首次武装）；
     * * 已武装静态帧大部分 MB 被强制命中，剩余 MB 仍全部自然 skip → 比值 100%，持续武装（不振荡）；
     * * 场景突变时完整路径 MB 普遍带残差，比值骤降 → 下一帧立即解除。
     * IDR 后/序列起始为 null（下一 P 帧必须使用严格死区）。
     * 跨 worker 进程确定性考虑：放宽判定依据"上一整帧"的全局占比，
     * 而非 worker 本地的帧内前缀计数，故输出与 motionWorkers 数量无关。
     */
    private ?array $prevFrameTier1 = null;

    /** 帧流水线开关（由入口 config frame_pipeline 注入，默认开启；置 false 退化同步编码） */
    public bool $framePipeline = true;
    /** 静态场景自适应 Tier1 放宽开关（由入口 config adaptive_skip 注入，默认关） */
    public bool $adaptiveSkip = false;
    /** 上一 P 帧自然 skip 占比达到该值才放宽当前帧（config adaptive_hit_ratio，默认 0.9） */
    public float $adaptiveHitRatio = 0.9;
    /** Tier1 经验绝对 SAD 限额（每 4x4 块，config adaptive_block_sad；0=按 qp 自动） */
    public int $adaptiveBlockSad = 0;

    private function framePipelineEnabled(): bool
    {
        return $this->framePipeline;
    }

    /**
     * 驱动层编排方式：
     *   startFrame($yuv0,$key0);
     *   while ($next = 取下一帧) { startFrame($nextYuv,$nextKey); $nals = finishFrame(); /* 输出上一帧 *\/ }
     *   $nals = finishFrame(); // 末帧 flush
     * 不使用流水线时直接 encodeFrame() 行为不变。
     */
    public function startFrame(string $yuvData, bool $isKeyframe): void
    {
        if (!$this->framePipelineEnabled()) {
            // 关闸退化为同步编码，但保持与流水线相同的双槽语义：
            // flight 存"待编码输入"，start(N+1) 时同步编码 N，finish(N) 取结果 —— 兼容驱动"先 start 后 emit"顺序
            if ($this->pipeFlight === null && $this->pipePending === null) {
                $this->pipeFlight = ['inlineTodo' => [$yuvData, $isKeyframe]];
                return;
            }
            $a = $this->pipeFlight;
            $this->pipePending = ['cachedNals' => $this->encodeFrameInline($a['inlineTodo'][0], $a['inlineTodo'][1])];
            $this->pipeFlight = ['inlineTodo' => [$yuvData, $isKeyframe]];
            return;
        }
        if ($this->pipeFlight !== null && $this->pipePending !== null) {
            throw new \RuntimeException('编码器流水线深度为 1：finishFrame 后才能 startFrame');
        }
        if ($this->pipeFlight === null) {
            // 首帧：准备 ctx，P 帧异步派发
            $ctx = $this->prepareSliceContext($yuvData, $isKeyframe);
            if ($ctx['sliceType'] === 0) $this->dispatchSlice($ctx);
            $this->pipeFlight = $ctx;
            return;
        }

        // 让在途帧 A 落地为 pending
        $ctxA = $this->pipeFlight;
        if ($ctxA['sliceType'] === 0) {
            $resultsA = $this->motionWorkerClient->collect();
            // 立即用 worker recon 拼出 A 的重建帧作为 B 的参考（与 CAVLC 后逐 MB 拷贝逐字节相同）
            $this->installReconFromResults($ctxA, $resultsA);
            // 提取 A 的 MV 地图，紧接的 dispatchSlice(B) 即用作时间种子
            $this->captureFrameMvs($ctxA, $resultsA);
            $ctxA['results'] = $resultsA;
            $this->pipePending = ['ctx' => $ctxA, 'cachedNals' => null];
        } else {
            // A 是 IDR：必须先编码 A 才能得到参考帧/计数器，提前编码并缓存 NAL
            $nalsA = $this->encodeSliceBody($ctxA, null);
            $this->pipePending = ['cachedNals' => $nalsA];
        }

        // 准备并异步派发 B（主进程紧接着在 finishFrame 中 CAVLC A，与 worker 并行）
        $ctxB = $this->prepareSliceContext($yuvData, $isKeyframe);
        if ($ctxB['sliceType'] === 0) {
            $this->dispatchSlice($ctxB);
        }
        $this->pipeFlight = $ctxB;
    }

    public function finishFrame(): array
    {
        if ($this->pipePending !== null) {
            $pending = $this->pipePending;
            $this->pipePending = null;
            if ($pending['cachedNals'] !== null) return $pending['cachedNals'];
            // flight（B）保持在途：worker 继续计算 B，主进程串行编码 A
            return $this->encodeSliceBody($pending['ctx'], $pending['ctx']['results'] ?? null);
        }
        if ($this->pipeFlight === null) {
            throw new \RuntimeException('finishFrame 前必须先 startFrame');
        }
        $ctx = $this->pipeFlight;
        $this->pipeFlight = null;
        if (isset($ctx['inlineTodo'])) {
            return $this->encodeFrameInline($ctx['inlineTodo'][0], $ctx['inlineTodo'][1]);
        }
        $results = null;
        if ($ctx['sliceType'] === 0) {
            $results = $this->motionWorkerClient->collect();
            $this->captureFrameMvs($ctx, $results);
        }
        return $this->encodeSliceBody($ctx, $results);
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        if ($this->framePipelineEnabled() && $this->pipeFlight === null && $this->pipePending === null) {
            $this->startFrame($yuvData, $isKeyframe);
            return $this->finishFrame();
        }
        return $this->encodeFrameInline($yuvData, $isKeyframe);
    }

    /** 非流水线同步编码（关闸或流水线外的直接调用） */
    private function encodeFrameInline(string $yuvData, bool $isKeyframe): array
    {
        $ctx = $this->prepareSliceContext($yuvData, $isKeyframe);
        $results = null;
        if ($ctx['sliceType'] === 0) {
            $this->dispatchSlice($ctx);
            $results = $this->motionWorkerClient->collect();
            $this->captureFrameMvs($ctx, $results);
        }
        return $this->encodeSliceBody($ctx, $results);
    }

    /** 帧级准备：slice 类型判定 + YUV 平面宏块对齐填充（与运动估计/CAVLC 无关，可提前） */
    private function prepareSliceContext(string $yuvData, bool $isIDR): array
    {
        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        $mbAlignedWidth = $mbWidth * 16;
        $mbAlignedHeight = $mbHeight * 16;
        $ySize = $this->width * $this->height;
        $uvSize = intdiv($ySize, 4);
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        // 将输入图像扩展到宏块对齐尺寸（边缘复制填充）
        if ($mbAlignedWidth !== $this->width || $mbAlignedHeight !== $this->height) {
            $expandedY = '';
            $padRight = $mbAlignedWidth - $this->width;
            for ($y = 0; $y < $this->height; $y++) {
                $row = substr($yPlane, $y * $this->width, $this->width);
                $lastPixel = $row[$this->width - 1];
                $expandedY .= $row . str_repeat($lastPixel, $padRight);
            }
            $padBottom = $mbAlignedHeight - $this->height;
            $lastRow = substr($expandedY, ($this->height - 1) * $mbAlignedWidth, $mbAlignedWidth);
            for ($y = 0; $y < $padBottom; $y++) {
                $expandedY .= $lastRow;
            }
            $yPlane = $expandedY;

            $uvW = (int)($this->width / 2);
            $uvH = (int)($this->height / 2);
            $padRightUv = (int)($mbAlignedWidth / 2) - $uvW;
            $padBottomUv = (int)($mbAlignedHeight / 2) - $uvH;
            foreach (['uPlane', 'vPlane'] as $planeName) {
                $expandedUV = '';
                for ($y = 0; $y < $uvH; $y++) {
                    $row = substr($$planeName, $y * $uvW, $uvW);
                    $lastPixel = $row[$uvW - 1];
                    $expandedUV .= $row . str_repeat($lastPixel, $padRightUv);
                }
                $lastRowUv = substr($expandedUV, ($uvH - 1) * (int)($mbAlignedWidth / 2), (int)($mbAlignedWidth / 2));
                for ($y = 0; $y < $padBottomUv; $y++) {
                    $expandedUV .= $lastRowUv;
                }
                $$planeName = $expandedUV;
            }
        }

        $usePFrame = $this->enableInter && !$isIDR && $this->refYPlane !== null;
        return [
            'isIDR' => $isIDR,
            'sliceType' => $usePFrame ? 0 : 2,
            'yPlane' => $yPlane,
            'uPlane' => $uPlane,
            'vPlane' => $vPlane,
            'mbWidth' => $mbWidth,
            'mbHeight' => $mbHeight,
            'aw' => $mbAlignedWidth,
            'ah' => $mbAlignedHeight,
            'results' => null,
        ];
    }

    /** 异步派发 P 帧运动估计（条带化协议：job 只含 x/y/range，当前帧平面按行条带发送） */
    private function dispatchSlice(array $ctx): void
    {
        $mbWidth = $ctx['mbWidth'];
        $mbHeight = $ctx['mbHeight'];
        $jobs = [];
        for ($y = 0; $y < $mbHeight; $y++) {
            for ($x = 0; $x < $mbWidth; $x++) {
                $jobs[$y * $mbWidth + $x] = [$x, $y, 32];
            }
        }
        $client = $this->motionWorkerClient ??= new MotionWorkerClient(0, $this->motionWorkers, $this->motionOptions);
        // v5：mvp_seed 开启且上一帧为同网格 P 帧时，随批次下发前一帧 MV 种子地图；
        // IDR 后首 P 帧或尺寸不一致时 prevFrameMvs=null，负载为空、搜索从 (0,0) 起。
        $seedMap = '';
        $seedW = $seedH = 0;
        if ($this->mvpSeed && $this->prevFrameMvs !== null
            && $this->prevFrameMvs['w'] === $mbWidth && $this->prevFrameMvs['h'] === $mbHeight) {
            $seedMap = MotionWorkerProtocol::encodeMvMap($mbWidth, $mbHeight, $this->prevFrameMvs['mvs']);
            $seedW = $mbWidth;
            $seedH = $mbHeight;
        }
        // v7：上一 P 帧（同网格）完整路径 MB 的自然 skip 占比达标 → 本帧整帧下发经验绝对 SAD 限额；
        // IDR 后首 P 帧（prevFrameTier1=null）、尺寸不一致、未达标或开关关闭时 0（严格死区）。
        $tier1BlockSad = 0;
        if ($this->adaptiveSkip && $this->prevFrameTier1 !== null
            && $this->prevFrameTier1['w'] === $mbWidth && $this->prevFrameTier1['h'] === $mbHeight
            && $this->prevFrameTier1['total'] > 0) {
            $evaluated = $this->prevFrameTier1['total'] - $this->prevFrameTier1['forced'];
            // 全部 MB 均被强制（evaluated=0）时无反证，维持武装；否则以完整路径 MB 自然 skip 率判定
            $ratioOk = $evaluated <= 0
                || $this->prevFrameTier1['nat'] / $evaluated >= $this->adaptiveHitRatio;
            if ($ratioOk) {
                $tier1BlockSad = $this->adaptiveBlockSad > 0
                    ? $this->adaptiveBlockSad
                    : $this->adaptiveBlockSadAuto($this->qp);
            }
        }
        $client->dispatch(
            $this->width,
            $this->height,
            $ctx['aw'],
            $ctx['ah'],
            $this->qp,
            $this->refYPlane,
            $this->refUPlane,
            $this->refVPlane,
            $ctx['yPlane'],
            $mbWidth,
            $mbHeight,
            $jobs,
            $seedMap,
            $seedW,
            $seedH,
            $tier1BlockSad
        );
    }

    /**
     * 经验绝对 SAD 限额的 qp 自动值（adaptive_block_sad=0 时）。
     * 基准 96@qp10 由 TR-3 实验标定（纯静态素材对源 PSNR 损失 0.01dB、
     * 对 seed 路径 PSNR 60dB/min58dB；噪声突变 1 帧解除）；
     * 量化步长每 +6qp 翻倍，允许的像素差/SAD 同比例放大。
     */
    private function adaptiveBlockSadAuto(int $qp): int
    {
        $base = 96; // qp=10 基准（每 4x4 块 SAD，实验标定）
        $scale = 2 ** (($qp - 10) / 6);
        return max(1, min(MotionWorkerProtocol::MAX_BLOCK_SAD, (int)round($base * $scale)));
    }

    /** 收齐 P 帧 worker 结果后：统计本帧自然 skip 占比（供下帧自适应），并提取整帧 MV 作时间 MVP 种子 */
    private function captureFrameMvs(array $ctx, array $results): void
    {
        // v7：自然 skip = 完整搜索/DCT 后 cbp=0 且 MV=0 且非 Tier1 命中（result[9]）。
        // 强制命中 MB 单列：武装保持率只在完整路径 MB 上评估（dispatchSlice），
        // 场景突变时完整路径 MB 的自然 skip 数骤降，下一帧立即解除武装。
        $nat = 0;
        $forced = 0;
        foreach ($results as $result) {
            $isForced = !empty($result[9]);
            if ($isForced) {
                $forced++;
            } elseif ($result[3] === 0 && $result[0] === 0 && $result[1] === 0) {
                // 自然 skip 只在完整路径 MB 中计数：强制命中 MB 不参与武装保持率分母，
                // 场景突变时它们不提供反证，反证全部来自完整路径 MB（见 dispatchSlice）。
                $nat++;
            }
        }
        $this->prevFrameTier1 = [
            'w' => $ctx['mbWidth'], 'h' => $ctx['mbHeight'],
            'nat' => $nat, 'forced' => $forced, 'total' => count($results),
        ];
        if (!$this->mvpSeed) {
            $this->prevFrameMvs = null;
            return;
        }
        $mvs = [];
        foreach ($results as $result) $mvs[] = [$result[0], $result[1]];
        $this->prevFrameMvs = ['w' => $ctx['mbWidth'], 'h' => $ctx['mbHeight'], 'mvs' => $mvs];
    }

    /**
     * 用 worker 返回的逐 MB recon 直接拼出整帧重建平面。
     * 主进程 CAVLC 原本也是把同一份 worker recon 逐行 substr_replace 进重建平面，
     * 故这里的结果与 CAVLC 完成后的 recon 平面逐字节相同（含边缘填充宏块）。
     */
    private function installReconFromResults(array $ctx, array $results): void
    {
        $mbWidth = $ctx['mbWidth'];
        $mbHeight = $ctx['mbHeight'];
        $aw = $ctx['aw'];
        $cw = intdiv($aw, 2);
        $reconY = str_repeat("\0", $aw * $ctx['ah']);
        $reconU = str_repeat("\0", $cw * intdiv($ctx['ah'], 2));
        $reconV = $reconU;
        for ($my = 0; $my < $mbHeight; $my++) {
            for ($r = 0; $r < 16; $r++) {
                $line = '';
                for ($mx = 0; $mx < $mbWidth; $mx++) {
                    $line .= substr($results[$my * $mbWidth + $mx][6], $r * 16, 16);
                }
                $reconY = substr_replace($reconY, $line, ($my * 16 + $r) * $aw, $aw);
            }
            for ($r = 0; $r < 8; $r++) {
                $lineU = '';
                $lineV = '';
                for ($mx = 0; $mx < $mbWidth; $mx++) {
                    $res = $results[$my * $mbWidth + $mx];
                    $lineU .= substr($res[7], $r * 8, 8);
                    $lineV .= substr($res[8], $r * 8, 8);
                }
                $reconU = substr_replace($reconU, $lineU, ($my * 8 + $r) * $cw, $cw);
                $reconV = substr_replace($reconV, $lineV, ($my * 8 + $r) * $cw, $cw);
            }
        }
        $this->refYPlane = $reconY;
        $this->refUPlane = $reconU;
        $this->refVPlane = $reconV;
        $this->refInts = null;
        $this->reconPrefetched = true;
    }

    public function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $nals = $this->encodeFrame($yuvData, $isKeyframe);
        return end($nals) ?: '';
    }

    /** slice 头 + 宏块 CAVLC 主循环（严格光栅顺序），P 帧结果由参数传入 */
    private function encodeSliceBody(array $ctx, ?array $motionResults): array
    {
        $isIDR = $ctx['isIDR'];
        $sliceType = $ctx['sliceType'];
        $yPlane = $ctx['yPlane'];
        $uPlane = $ctx['uPlane'];
        $vPlane = $ctx['vPlane'];
        $mbWidth = $ctx['mbWidth'];
        $mbHeight = $ctx['mbHeight'];
        $this->picWidthInMbs = $mbWidth;
        $this->mbAlignedWidth = $ctx['aw'];
        $this->mbAlignedHeight = $ctx['ah'];

        $prefixNals = [];
        if ($isIDR) {
            // I帧：重置参考帧和计数器（在真正编码该帧时执行，流水线中同样有序）
            $this->refYPlane = null;
            $this->refUPlane = null;
            $this->refVPlane = null;
            // IDR 后参考链断裂：下一 P 帧无时间 MVP 种子、Tier1 命中率统计也清空（严格死区起算）
            $this->prevFrameMvs = null;
            $this->prevFrameTier1 = null;
            $this->frameNum = 0;
            $this->idrPicId++;
            $this->poc = 0;
            $prefixNals[] = $this->generateSPS();
            $prefixNals[] = $this->generatePPS();
        }

        $bits = '';
        $bits .= $this->ue(0);           // first_mb_in_slice
        $bits .= $this->ue($sliceType);  // slice_type
        $bits .= $this->ue(0);           // pic_parameter_set_id

        $log2MaxFrameNum = $this->log2MaxFrameNumMinus4 + 4;
        $frameNumBits = $log2MaxFrameNum;
        $frameNumValue = $this->frameNum & ((1 << $frameNumBits) - 1);
        $bits .= $this->u($frameNumValue, $frameNumBits);

        if ($isIDR) $bits .= $this->ue($this->idrPicId);

        $log2MaxPicOrderCntLsb = $this->log2MaxPicOrderCntLsbMinus4 + 4;
        $pocLsb = $this->poc & ((1 << $log2MaxPicOrderCntLsb) - 1);
        $bits .= $this->u($pocLsb, $log2MaxPicOrderCntLsb);

        if ($sliceType === 0) {
            $bits .= '0'; // num_ref_idx_active_override_flag
            $bits .= '0'; // ref_pic_list_modification_flag_l0
        }

        if ($isIDR) {
            $bits .= '0';
            $bits .= '0';
        } else {
            $bits .= '0';
        }

        if ($sliceType === 0) {
            $bits .= $this->se($this->pFrameQpDelta);
        } else {
            $bits .= $this->se(0);
        }

        // 禁用deblocking filter（编码器未实现去块滤波，需与解码器保持一致）
        $bits .= $this->ue(1);

        $topNzLuma = array_fill(0, $mbWidth * 4, 0);
        $topNzCb = array_fill(0, $mbWidth * 2, 0);
        $topNzCr = array_fill(0, $mbWidth * 2, 0);
        $topIntra4x4Mode = array_fill(0, $mbWidth * 4, -1);

        $this->motionWorkerResults = $sliceType === 0 ? $motionResults : [];

        // 重置MV缓存（mvTopRow保留上行的MV，mvLeftCol每行重置）
        $this->mvTopRow = [];
        $this->mvLeftCol = [null, null, null, null];
        $this->mvTopLeft = null;

        // 初始化本地解码重建帧（使用宏块对齐尺寸，与解码器一致）
        $reconYSize = $this->mbAlignedWidth * $this->mbAlignedHeight;
        $reconUvW = intdiv($this->mbAlignedWidth, 2);
        $reconUvH = intdiv($this->mbAlignedHeight, 2);
        $reconUvSize = $reconUvW * $reconUvH;
        if ($sliceType === 0 && $this->reconPrefetched
            && strlen($this->refYPlane) === $reconYSize) {
            // 流水线：recon 已在 settle 阶段整帧拼好（即当前参考帧内容），CAVLC 不再逐 MB 拷贝
            $this->reconYPlane = $this->refYPlane;
            $this->reconUPlane = $this->refUPlane;
            $this->reconVPlane = $this->refVPlane;
            $this->reconPreassembled = true;
        } else {
            $this->reconYPlane = str_repeat("\x80", $reconYSize);
            $this->reconUPlane = str_repeat("\x80", $reconUvSize);
            $this->reconVPlane = str_repeat("\x80", $reconUvSize);
            $this->reconPreassembled = false;
        }

        $mbSkipRun = 0;
        $isPSlice = $sliceType === 0;

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            $leftAvailable = false;
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            $leftIntra4x4Mode = [-1, -1, -1, -1];
            $this->mvLeftCol = [null, null, null, null];
            $this->mvTopLeft = null;
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                $nextMvTopLeft = $this->mvTopRow[$mbX * 4 + 3] ?? null;
                if ($isPSlice) {
                    $mbBits = $this->encodePMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode,
                        $this->refYPlane
                    );
                    if ($this->lastMbWasSkip) {
                        $mbSkipRun++;
                    } else {
                        $bits .= $this->ue($mbSkipRun);
                        $bits .= $mbBits;
                        $mbSkipRun = 0;
                    }
                } else {
                    $mbBits = $this->encodeMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode
                    );
                    $bits .= $mbBits;

                    $intraMv = [0, 0, -1];
                    $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
                    $this->mvTopRow[$mbX * 4 + 0] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 1] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 2] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 3] = $intraMv;
                }
                $this->mvTopLeft = $nextMvTopLeft;
                $leftAvailable = true;
            }
        }
        if ($isPSlice && $mbSkipRun > 0) {
            $bits .= $this->ue($mbSkipRun);
        }
        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $rbsp = $this->bitsToBytes($bits);
        $nalType = $isIDR ? 5 : 1;
        $nal = $this->rbspToNal($rbsp, $nalType);

        // 保存重建后的帧作为下一帧的参考帧（流水线 P 帧两者本就是同一内容）
        $this->refYPlane = $this->reconYPlane;
        $this->refUPlane = $this->reconUPlane;
        $this->refVPlane = $this->reconVPlane;
        $this->refInts = null;
        $this->reconPreassembled = false;
        $this->reconPrefetched = false;

        $this->frameNum++;
        $this->poc += 2;

        $prefixNals[] = $nal;
        return $prefixNals;
    }
}
