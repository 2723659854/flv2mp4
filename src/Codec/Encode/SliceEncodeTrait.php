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

    /** 帧流水线总开关（FLV2MP4_FRAME_PIPELINE=0 关闭，用于 A/B 与灰度回退） */
    private function framePipelineEnabled(): bool
    {
        static $enabled = null;
        return $enabled ??= getenv('FLV2MP4_FRAME_PIPELINE') !== '0';
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
            $ctxA['results'] = $resultsA;
            $this->pipePending = ['ctx' => $ctxA, 'cachedNals' => null];
        } else {
            // A 是 IDR：必须先编码 A 才能得到参考帧/计数器，提前编码并缓存 NAL
            $nalsA = $this->encodeSliceBody($ctxA, null);
            $this->pipePending = ['cachedNals' => $nalsA];
        }

        // 准备并异步派发 B（主进程紧接着在 finishFrame 中 CAVLC A，与 worker 并行）
        $ctxB = $this->prepareSliceContext($yuvData, $isKeyframe);
        if ($ctxB['sliceType'] === 0) $this->dispatchSlice($ctxB);
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
        if ($ctx['sliceType'] === 0) $results = $this->motionWorkerClient->collect();
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
        $client = $this->motionWorkerClient ??= new MotionWorkerClient(workers: $this->motionWorkers);
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
            $jobs
        );
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
