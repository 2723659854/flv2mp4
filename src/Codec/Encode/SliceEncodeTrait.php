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
     * 场景切换检测：保存上一帧【输入】（宏块对齐后）的 Y 平面。
     * P 帧编码器只有 P_Skip/P_L0_16x16、色度零残差，硬切后残差补不回大面变化，
     * 旧画面会作为参考污染整个源 GOP。检测到切换时把该帧强制升级为 IDR 截断传播。
     */
    private ?string $scenePrevY = null;
    private int $scenePrevAw = 0;
    private int $scenePrevAh = 0;
    /**
     * 连续"高 SAD"帧计数。真硬切是突发单帧（上一帧平稳），摇移/快速运动则连续多帧高 SAD。
     * 只在高 SAD 突发首帧强制 IDR；持续运动期间保持 P 帧，避免高运动场景帧帧 IDR 拖垮编码速度。
     */
    private int $sceneHighRun = 0;
    /** 距下次允许强制 IDR 的输出帧计数；频闪连闪期限流，源 IDR 到达后清零 */
    private int $sceneForceCooldown = 0;

    /** 场景切换触发阈值和局部硬切判定常量定义在 H264Encoder 类中，兼容 PHP 8.1。 */

    /**
     * 帧间亮度变化抽样统计：每个 16x16 宏块抽 4 个点 (4,4)/(11,4)/(4,11)/(11,11)，
     * 360p 全帧仅 3680 点（约 1ms 级）。
     * 返回 [全局平均绝对差, 全局强差点占比, 局部剧变宏块占比, 剧变宏块平均绝对差, 全局平均带符号差]。
     * 全帧硬切时绝大多数块同时剧变（mean/ratio 高）；屏幕采集硬切仅播放窗区域剧变，
     * 靠 hardRatio/activeMean 识别；普通运动只稀疏边缘变化，三项都低。
     * 灯光频闪/曝光跳变则是全帧同向变化（第 5 项绝对值接近第 1 项）。
     *
     * @return array{0:float,1:float,2:float,3:float,4:float}
     */
    private function measureSceneChange(string $curY, int $aw, int $mbWidth, int $mbHeight): array
    {
        // 上一帧（宏块对齐）的 Y 平面，作为比对基准
        $prev = $this->scenePrevY;
        // 全部抽样点的绝对差之和（用于求全局平均绝对差）
        $sum = 0;
        // 带符号差之和（用于识别全帧同向的亮度漂移，如频闪/曝光跳变）
        $signedSum = 0;
        // 绝对差 >= 32 的抽样点个数（强差点）
        $high = 0;
        // 已统计的抽样点总数
        $n = 0;
        // 「剧变宏块」个数（4 个抽样点中至少 3 点剧变）
        $hardMb = 0;
        // 剧变宏块的绝对差累计
        $hardSum = 0;
        // 从宏块左上角 (4,4)/(11,4) 到下方 (4,11)/(11,11) 的行间距：7 行像素 × 行跨度
        $rowGap = 7 * $aw;
        // 逐宏块行遍历
        for ($my = 0; $my < $mbHeight; $my++) {
            // 该宏块行的首行像素偏移（每宏块 16 行）
            $mbRowBase = $my * 16 * $aw;
            // 逐宏块列遍历
            for ($mx = 0; $mx < $mbWidth; $mx++) {
                // 宏块左上角像素偏移（每宏块 16 像素）
                $p = $mbRowBase + $mx * 16;
                // 下方抽样点的偏移（相对上方 +7 行）
                $p2 = $p + $rowGap;
                // 本宏块 4 个抽样点的绝对差之和
                $mbSum = 0;
                // 本宏块强差点个数
                $mbHigh = 0;
                // 抽样点一：偏 (4,4)
                $d = ord($curY[$p + 4]) - ord($prev[$p + 4]);
                $signedSum += $d;
                if ($d < 0) $d = -$d;
                $mbSum += $d;
                if ($d >= 32) $mbHigh++;
                // 抽样点二：偏 (11,4)
                $d = ord($curY[$p + 11]) - ord($prev[$p + 11]);
                $signedSum += $d;
                if ($d < 0) $d = -$d;
                $mbSum += $d;
                if ($d >= 32) $mbHigh++;
                // 抽样点三：偏 (4,11)（下一抽样行）
                $d = ord($curY[$p2 + 4]) - ord($prev[$p2 + 4]);
                $signedSum += $d;
                if ($d < 0) $d = -$d;
                $mbSum += $d;
                if ($d >= 32) $mbHigh++;
                // 抽样点四：偏 (11,11)
                $d = ord($curY[$p2 + 11]) - ord($prev[$p2 + 11]);
                $signedSum += $d;
                if ($d < 0) $d = -$d;
                $mbSum += $d;
                if ($d >= 32) $mbHigh++;
                // 抽样点计数 +4
                $n += 4;
                // 累加全局绝对差与强差点
                $sum += $mbSum;
                $high += $mbHigh;
                // 4 点中有 3 点以上剧变：记为「剧变宏块」
                if ($mbHigh >= 3) {
                    $hardMb++;
                    $hardSum += $mbSum;
                }
            }
        }
        // 宏块总数（用于求剧变宏块占比）
        $mbCount = $mbWidth * $mbHeight;
        // 返回：全局平均绝对差、强差点占比、剧变宏块占比、剧变宏块平均绝对差、全局带符号均值
        return [
            $sum / $n,
            $high / $n,
            $hardMb / $mbCount,
            $hardMb > 0 ? $hardSum / ($hardMb * 4) : 0.0,
            $signedSum / $n,
        ];
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
        // 流水线深度为 1：flight 与 pending 同时存在说明上一帧还没 finishFrame
        if ($this->pipeFlight !== null && $this->pipePending !== null) {
            throw new \RuntimeException('编码器流水线深度为 1：finishFrame 后才能 startFrame');
        }
        // 首个在途帧：无需先落地上一帧
        if ($this->pipeFlight === null) {
            // 首帧：准备 ctx，P 帧异步派发
            $ctx = $this->prepareSliceContext($yuvData, $isKeyframe);
            // sliceType=0 表示 P 片，立即派发给 worker 并行计算
            if ($ctx['sliceType'] === 0) $this->dispatchSlice($ctx);
            $this->pipeFlight = $ctx;
            return;
        }

        // 让在途帧 A 落地为 pending
        $ctxA = $this->pipeFlight;
        if ($ctxA['sliceType'] === 0) {
            // P 帧：收齐 worker 结果（阻塞）
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
        // 仅 P 帧需要预派发运动搜索
        if ($ctxB['sliceType'] === 0) {
            $this->dispatchSlice($ctxB);
        }
        $this->pipeFlight = $ctxB;
    }

    /**
     * 输出待编码帧的 NAL 列表：优先输出 pending（上一帧），否则落地当前 flight。
     *
     * @return array NAL 字节串数组（IDR 时首尾含 SPS/PPS）
     */
    public function finishFrame(): array
    {
        // 有 pending 时先输出它（保持输出顺序与输入顺序一致）
        if ($this->pipePending !== null) {
            $pending = $this->pipePending;
            $this->pipePending = null;
            // IDR 已提前编码：直接返回缓存的 NAL
            if ($pending['cachedNals'] !== null) return $pending['cachedNals'];
            // flight（B）保持在途：worker 继续计算 B，主进程串行编码 A
            return $this->encodeSliceBody($pending['ctx'], $pending['ctx']['results'] ?? null);
        }
        // 没有 pending 也没有 flight：调用顺序有误
        if ($this->pipeFlight === null) {
            throw new \RuntimeException('finishFrame 前必须先 startFrame');
        }
        $ctx = $this->pipeFlight;
        $this->pipeFlight = null;
        $results = null;
        // P 帧：收齐运动搜索结果
        if ($ctx['sliceType'] === 0) $results = $this->motionWorkerClient->collect();
        return $this->encodeSliceBody($ctx, $results);
    }

    /**
     * 编码一帧并返回 NAL 列表（对外统一入口）。
     *
     * @param string $yuvData   YUV420p 原始帧
     * @param bool   $isKeyframe 是否为关键帧（IDR）
     * @return array NAL 列表
     */
    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        // 流水线空闲：走 start/finish 标准路径
        if ($this->pipeFlight === null && $this->pipePending === null) {
            $this->startFrame($yuvData, $isKeyframe);
            return $this->finishFrame();
        }
        // 流水线被占用：退化为同步编码，避免打乱在途帧时序
        return $this->encodeFrameInline($yuvData, $isKeyframe);
    }

    /** 非流水线同步编码（流水线状态被占用时的直接调用） */
    private function encodeFrameInline(string $yuvData, bool $isKeyframe): array
    {
        // 帧级准备（平面填充 + 片类型判定）
        $ctx = $this->prepareSliceContext($yuvData, $isKeyframe);
        $results = null;
        // P 帧：派发后立即阻塞收齐（无并行收益，但保证顺序）
        if ($ctx['sliceType'] === 0) {
            $this->dispatchSlice($ctx);
            $results = $this->motionWorkerClient->collect();
        }
        return $this->encodeSliceBody($ctx, $results);
    }

    /**
     * 将输入 YUV 平面扩展到宏块对齐尺寸（边缘复制填充）。
     * P/I 路径与 B 帧路径共用，避免重复实现。
     *
     * @return array{0:string,1:string,2:string,3:int,4:int,5:int,6:int} [yPlane,uPlane,vPlane,mbWidth,mbHeight,aw,ah]
     */
    private function padYuvPlanes(string $yuvData): array
    {
        // 宏块列数（向上取整）
        $mbWidth = (int)ceil($this->width / 16);
        // 宏块行数（向上取整）
        $mbHeight = (int)ceil($this->height / 16);
        // 宏块对齐宽度（16 的倍数）
        $mbAlignedWidth = $mbWidth * 16;
        // 宏块对齐高度（16 的倍数）
        $mbAlignedHeight = $mbHeight * 16;
        // Y 平面字节数
        $ySize = $this->width * $this->height;
        // 每个色度平面字节数（4:2:0 为 Y 的 1/4）
        $uvSize = intdiv($ySize, 4);
        // 从 YUV420p 缓冲中切出 Y 平面
        $yPlane = substr($yuvData, 0, $ySize);
        // 切出 U 平面
        $uPlane = substr($yuvData, $ySize, $uvSize);
        // 切出 V 平面
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        // 只有尺寸未对齐时才需要填充
        if ($mbAlignedWidth !== $this->width || $mbAlignedHeight !== $this->height) {
            // 右侧需要补的列数
            $padRight = $mbAlignedWidth - $this->width;
            // 填充后的 Y 行集合
            $rows = [];
            // 逐行复制原行并做右边界复制填充（edge replication）
            for ($y = 0; $y < $this->height; $y++) {
                $row = substr($yPlane, $y * $this->width, $this->width);
                $rows[] = $row . str_repeat($row[$this->width - 1], $padRight);
            }
            // 底部不足一个宏块行时，用最后一行重复填充
            if ($mbAlignedHeight > $this->height) {
                $lastRow = end($rows);
                for ($y = 0, $yn = $mbAlignedHeight - $this->height; $y < $yn; $y++) $rows[] = $lastRow;
            }
            // 拼回完整 Y 平面
            $yPlane = implode('', $rows);

            // 色度平面实际宽度（像素）
            $uvW = (int)($this->width / 2);
            // 色度平面实际高度（行）
            $uvH = (int)($this->height / 2);
            // 色度对齐宽度（宏块对齐宽度的一半）
            $uvAlineW = (int)($mbAlignedWidth / 2);
            // 色度右侧需补的列数
            $padRightUv = $uvAlineW - $uvW;
            // 色度底部需补的行数
            $padBottomUv = (int)($mbAlignedHeight / 2) - $uvH;
            // U/V 两个平面按同样的规则填充（变量变量名复用同一段代码）
            foreach (['uPlane', 'vPlane'] as $planeName) {
                $rowsUv = [];
                // 逐行右边界复制填充
                for ($y = 0; $y < $uvH; $y++) {
                    $row = substr($$planeName, $y * $uvW, $uvW);
                    $rowsUv[] = $row . str_repeat($row[$uvW - 1], $padRightUv);
                }
                // 底部重复最后一行
                if ($padBottomUv > 0) {
                    $lastRowUv = end($rowsUv);
                    for ($y = 0; $y < $padBottomUv; $y++) $rowsUv[] = $lastRowUv;
                }
                // 写回对应平面
                $$planeName = implode('', $rowsUv);
            }
        }
        // 返回三分量平面、宏块尺寸与对齐尺寸
        return [$yPlane, $uPlane, $vPlane, $mbWidth, $mbHeight, $mbAlignedWidth, $mbAlignedHeight];
    }

    /** 帧级准备：slice 类型判定 + YUV 平面宏块对齐填充（与运动估计/CAVLC 无关，可提前） */
    private function prepareSliceContext(string $yuvData, bool $isIDR): array
    {
        [
            $yPlane, $uPlane, $vPlane,
            $mbWidth, $mbHeight, $mbAlignedWidth, $mbAlignedHeight,
        ] = $this->padYuvPlanes($yuvData);

        // 场景切换检测（在派发 P 帧运动估计之前定案，流水线 start(N+1) 与同步路径都在此分叉，
        // 不存在"N+1 已按错误参考派发"的时序问题）：与上一帧【输入】做抽样 SAD，
        // 超阈值则把本帧强制升级 IDR，阻止硬切旧画面沿 P 参考链污染整个源 GOP。
        // B 帧模式下禁用场景切换强制 IDR：B 三元组已按未来锚点定案，
        // 中途把 P 锚点升级成 IDR 会重置 POC/frame_num，破坏已排定 B 片的 POC 连续性。
        if (!$this->bFrames && !$isIDR && $this->enableInter && $this->refYPlane !== null
            && $this->scenePrevY !== null
            && $this->scenePrevAw === $mbAlignedWidth
            && $this->scenePrevAh === $mbAlignedHeight) {
            if ($this->sceneForceCooldown > 0) $this->sceneForceCooldown--;
            [$sceneMean, $sceneRatio, $sceneHardRatio, $sceneActiveMean, $sceneSignedMean] = $this->measureSceneChange($yPlane, $mbAlignedWidth, $mbWidth, $mbHeight);
            // 规则一：全帧硬切（全局均值+强差点占比双阈值）；
            // 规则二：屏幕采集类局部硬切——大面积静态边框会稀释全局指标，
            // 改看"≥3/4 抽样点剧变"的宏块覆盖率及其自身幅度
            $sceneDetected = ($sceneMean >= self::SCENE_SAD_THRESHOLD && $sceneRatio >= self::SCENE_RATIO_THRESHOLD)
                || ($sceneHardRatio >= self::SCENE_REGION_MB_RATIO && $sceneActiveMean >= self::SCENE_REGION_MEAN);
            // 灯光频闪/曝光跳变：全帧亮度同向偏移（|带符号均值|≈绝对均值），空间内容并未切换。
            // 硬切的差值方向随机，带符号均值接近 0。频闪强制 IDR 收益极低却要付出整帧帧内编码代价。
            if ($sceneDetected && $sceneMean > 0.0
                && abs($sceneSignedMean) / $sceneMean >= self::SCENE_FLASH_SIGNED_RATIO) {
                $sceneDetected = false;
            }
            if ($sceneDetected) {
                $this->sceneHighRun++;
                // 仅突发首帧（真硬切特征）升级 IDR；连续高 SAD 是摇移/剧烈运动，保持 P 帧。
                // 持续运动超过 SCENE_SUSTAIN_MAX 帧后再给一次机会，兜住"运动中硬切"的极端情况。
                // 冷却期内（频闪连闪/切后短时间）不重复强制，等源 GOP 的自然 IDR 即可。
                if (($this->sceneHighRun === 1 || $this->sceneHighRun >= self::SCENE_SUSTAIN_MAX)
                    && $this->sceneForceCooldown === 0) {
                    $isIDR = true;
                    $this->sceneForceCooldown = self::SCENE_FORCE_COOLDOWN;
                    if ($this->sceneHighRun >= self::SCENE_SUSTAIN_MAX) $this->sceneHighRun = 0;
                }
            } else {
                $this->sceneHighRun = 0;
            }
        } elseif ($isIDR) {
            // 源自然 IDR 已刷新参考，强制 IDR 冷却无需继续
            $this->sceneForceCooldown = 0;
        }
        // 保存当前帧【输入】作为下帧比对基准（IDR/P 后连续，分辨率变化时自然断档一帧）
        $this->scenePrevY = $yPlane;
        $this->scenePrevAw = $mbAlignedWidth;
        $this->scenePrevAh = $mbAlignedHeight;

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
        // 宏块列数
        $mbWidth = $ctx['mbWidth'];
        // 宏块行数
        $mbHeight = $ctx['mbHeight'];
        // 运动搜索任务表：键为光栅顺序的宏块下标，值为 [宏块列, 宏块行, 搜索半径]
        $jobs = [];
        for ($y = 0; $y < $mbHeight; $y++) {
            for ($x = 0; $x < $mbWidth; $x++) {
                // 快速模式把半径收紧到 8 像素
                $jobs[$y * $mbWidth + $x] = [$x, $y, $this->fastMotion ? min(8, $this->motionRange) : $this->motionRange];
            }
        }
        // 懒加载运动搜索客户端
        $client = $this->motionWorkerClient ??= new MotionWorkerClient(workers: $this->motionWorkers);
        // 异步派发：参考帧取上一帧重建平面，当前帧取本帧对齐后的平面
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
            $ctx['uPlane'],
            $ctx['vPlane'],
            $mbWidth,
            $mbHeight,
            $jobs,
            $this->inter8x8Enabled
        );
    }

    /**
     * 用 worker 返回的逐 MB recon 直接拼出整帧重建平面。
     * 主进程 CAVLC 原本也是把同一份 worker recon 逐行 substr_replace 进重建平面，
     * 故这里的结果与 CAVLC 完成后的 recon 平面逐字节相同（含边缘填充宏块）。
     */
    private function installReconFromResults(array $ctx, array $results): void
    {
        // 宏块列数
        $mbWidth = $ctx['mbWidth'];
        // 宏块行数
        $mbHeight = $ctx['mbHeight'];
        // 亮度平面行跨度（宏块对齐宽度）
        $aw = $ctx['aw'];
        // 色度平面行跨度（对齐宽度的一半）
        $cw = intdiv($aw, 2);
        // 行收集后一次性 implode：避免对整帧大串逐行 substr_replace（每帧上百 MB 内存搬移）
        $rowsY = [];
        $rowsU = [];
        $rowsV = [];
        // 逐宏块行拼接整帧
        for ($my = 0; $my < $mbHeight; $my++) {
            // 亮度每个宏块行 16 行像素
            for ($r = 0; $r < 16; $r++) {
                $line = '';
                for ($mx = 0; $mx < $mbWidth; $mx++) {
                    // 结果下标 [6] 为 worker 返回的 16x16 重建亮度块，取第 r 行的 16 字节
                    $line .= substr($results[$my * $mbWidth + $mx][6], $r * 16, 16);
                }
                $rowsY[] = $line;
            }
            // 色度每个宏块行 8 行像素
            for ($r = 0; $r < 8; $r++) {
                $lineU = '';
                $lineV = '';
                for ($mx = 0; $mx < $mbWidth; $mx++) {
                    $res = $results[$my * $mbWidth + $mx];
                    // 结果下标 [7]/[8] 分别为 8x8 重建 U/V 块
                    $lineU .= substr($res[7], $r * 8, 8);
                    $lineV .= substr($res[8], $r * 8, 8);
                }
                $rowsU[] = $lineU;
                $rowsV[] = $lineV;
            }
        }
        // 拼成完整三分量平面
        $reconY = implode('', $rowsY);
        $reconU = implode('', $rowsU);
        $reconV = implode('', $rowsV);
        // 直接作为下一帧的参考帧（此时参考帧内容即本帧重建结果）
        $this->refYPlane = $reconY;
        $this->refUPlane = $reconU;
        $this->refVPlane = $reconV;
        // 参考帧整数缓存失效，由使用方按需重建
        $this->refInts = null;
        // 标记 recon 已整帧预拼，CAVLC 阶段可跳过逐宏块拷贝
        $this->reconPrefetched = true;
    }

    /**
     * 编码一帧并只返回最后一个 NAL（切片数据 NAL，规范 7.3.2.9/7.3.3）。
     *
     * @param string $yuvData    YUV420p 帧
     * @param bool   $isKeyframe 是否 IDR
     * @return string 切片 NAL 字节串；无输出时返回空串
     */
    public function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        // 走统一编码入口
        $nals = $this->encodeFrame($yuvData, $isKeyframe);
        // 取最后一个 NAL（IDR 时为 slice，前面还有 SPS/PPS）
        return end($nals) ?: '';
    }

    /**
     * high 档 IDR 的 I16/I8 逐 GOP 决策入口：两条路径各自按最终配置完整试编一遍
     * （I16@qp 与 I8x8@qp+intra8x8QpOffset），取片 NAL 字节更小者（平手取 I8x8，
     * 其 QP 补偿带来更高重建质量）。每个 IDR 独立决策（IDR 即输出 GOP 的起点，
     * 场景切换强制 IDR 亦逐帧独立），P/B 片路径不受影响。
     * 重编无状态残留：每遍 encFrameBegin/encSliceBegin 复位 CABAC 引擎与帧级簿记、
     * recon 平面重建为中性灰，isIDR 分支复位参考平面/frameNum/poc；
     * 仅 idrPicId 保存/恢复避免双遍重复自增；I16 胜出时回填 I16 遍的重建参考帧，
     * 保证后续 P/B 的运动估计参考与码流内容一致。
     */
    private function encodeSliceBody(array $ctx, ?array $motionResults): array
    {
        // IDR 且启用 high/8x8 帧内/GOP 级双路决策时，进入 I16 与 I8x8 两遍试编比选
        if ($ctx['isIDR'] && $ctx['sliceType'] === 2
            && $this->profile === 'high' && $this->intra8x8Enabled
            && $this->intraGopDecisionEnabled) {
            // 备份 idr_pic_id，避免两遍试编各自自增（规范 7.4.3 中同一 IDR 只占一个编号）
            $savedIdrPicId = $this->idrPicId;
            // 第一遍：强制 16x16 帧内
            $this->forceIntra16Slice = true;
            $nals16 = $this->encodeSliceBodyOnce($ctx, $motionResults);
            $this->forceIntra16Slice = false;
            // 保存 I16 遍得到的重建参考帧，便于胜出时回填
            $ref16 = [$this->refYPlane, $this->refUPlane, $this->refVPlane];
            $this->idrPicId = $savedIdrPicId; // I16 遍已自增一次，I8x8 遍重写同一值
            // 第二遍：允许 8x8 帧内
            $nals8 = $this->encodeSliceBodyOnce($ctx, $motionResults);
            $this->idrPicId = $savedIdrPicId + 1; // 按单次 IDR 语义归位
            // 以切片 NAL 字节数作为代价比较（越小越省码率）
            $len16 = strlen(end($nals16));
            $len8 = strlen(end($nals8));
            // 平手时选 I8x8（其 QP 补偿带来更高重建质量）
            if ($len8 <= $len16) {
                $this->gopI8Wins++;
                return $nals8; // I8x8 胜出：参考帧已是 I8x8 遍重建
            }
            // I16 胜出：回填 I16 遍的重建参考帧，保证后续 P/B 的参考与码流一致
            [$this->refYPlane, $this->refUPlane, $this->refVPlane] = $ref16;
            $this->gopI16Wins++;
            return $nals16;
        }
        // 非 high 或未启用决策：单遍编码
        return $this->encodeSliceBodyOnce($ctx, $motionResults);
    }

    /** slice 头 + 宏块 CAVLC 主循环（严格光栅顺序），P 帧结果由参数传入 */
    private function encodeSliceBodyOnce(array $ctx, ?array $motionResults): array
    {
        // 是否 IDR 帧（I 片，nal_unit_type=5，规范 7.4.1.2.3）
        $isIDR = $ctx['isIDR'];
        // 片类型：0=P，2=I（规范 7.4.3 slice_type）
        $sliceType = $ctx['sliceType'];
        // 本帧（宏块对齐）三分量平面
        $yPlane = $ctx['yPlane'];
        $uPlane = $ctx['uPlane'];
        $vPlane = $ctx['vPlane'];
        // 宏块列/行数
        $mbWidth = $ctx['mbWidth'];
        $mbHeight = $ctx['mbHeight'];
        // 当前帧按宏块计的宽高（供宏块编码器计算邻居可用性）
        $this->picWidthInMbs = $mbWidth;
        $this->picHeightInMbs = $mbHeight; // CABAC 帧级簿记画布按此尺寸分配，漏设会导致 picMbType 全空
        // 宏块对齐尺寸（平面行跨度）
        $this->mbAlignedWidth = $ctx['aw'];
        $this->mbAlignedHeight = $ctx['ah'];

        // 前置 NAL（IDR 时为 SPS/PPS + slice）
        $prefixNals = [];
        if ($isIDR) {
            // I帧：重置参考帧和计数器（在真正编码该帧时执行，流水线中同样有序）
            $this->refYPlane = null;
            $this->refUPlane = null;
            $this->refVPlane = null;
            $this->frameNum = 0;
            // 每个 IDR 使用新的 idr_pic_id（规范 7.4.3.3）
            $this->idrPicId++;
            $this->poc = 0;
            // IDR 前必须重新发送参数集（规范 7.4.1.2.1/7.4.1.2.2）
            $prefixNals[] = $this->generateSPS();
            $prefixNals[] = $this->generatePPS();
        }

        // I 片 8x8 变换 QP 补偿：标准 DEQUANT8 缩放使 8x8 同 QP 的量化步长约为 4x4 的 2 倍，
        // I 帧重建劣化会放大后续全部 P/B 帧残差（实测全片 +16.8%），按偏移降低 I 片 QP。
        // 仅 high 且 I8x8 启用时生效；整片（qp_delta/量化/重建/cabQp 初始化）统一用补偿后 QP，
        // P/B 片独立起算不受影响。片尾 return 前恢复原 QP。
        $savedQp = null;
        if ($sliceType === 2 && $this->profile === 'high' && $this->intra8x8Enabled
            && !$this->forceIntra16Slice && $this->intra8x8QpOffset !== 0) {
            // I16 试编遍（forceIntra16Slice）不应用 8x8 QP 补偿：与 main 的 I16@qp 同配置
            $savedQp = $this->qp;
            $this->qp = max(0, min(51, $this->qp + $this->intra8x8QpOffset));
        }

        $bits = '';
        // 片头前三个 ue(v) 依次为 first_mb_in_slice / slice_type / pic_parameter_set_id（规范 7.3.3）
        $bits .= $this->ue(0);           // first_mb_in_slice
        $bits .= $this->ue($sliceType);  // slice_type
        $bits .= $this->ue(0);           // pic_parameter_set_id

        // frame_num 位宽 = log2_max_frame_num_minus4 + 4（规范 7.4.3）
        $log2MaxFrameNum = $this->log2MaxFrameNumMinus4 + 4;
        $frameNumBits = $log2MaxFrameNum;
        // 按位宽取模，保证 frame_num 不越界
        $frameNumValue = $this->frameNum & ((1 << $frameNumBits) - 1);
        $bits .= $this->u($frameNumValue, $frameNumBits);

        // idr_pic_id：仅在 IDR 片出现，用 ue(v) 编码
        if ($isIDR) $bits .= $this->ue($this->idrPicId);

        // pic_order_cnt_lsb 位宽 = log2_max_pic_order_cnt_lsb_minus4 + 4（规范 7.4.3）
        $log2MaxPicOrderCntLsb = $this->log2MaxPicOrderCntLsbMinus4 + 4;
        $pocLsb = $this->poc & ((1 << $log2MaxPicOrderCntLsb) - 1);
        $bits .= $this->u($pocLsb, $log2MaxPicOrderCntLsb);

        // P 片的参考图像列表相关语法（规范 7.3.3）
        if ($sliceType === 0) {
            $bits .= '0'; // num_ref_idx_active_override_flag
            $bits .= '0'; // ref_pic_list_modification_flag_l0
        }

        // 参考图像标记语法（规范 7.3.3.3）：IDR 需写 no_output_of_prior_pics_flag / long_term_reference_flag
        if ($isIDR) {
            $bits .= '0';
            $bits .= '0';
        } else {
            $bits .= '0';
        }

        // CABAC：cabac_init_idc 必须写在 slice_qp_delta 之前（spec 7.3.3 顺序，；写在 qp_delta 之后会导致 P/B 片头错位）
        if (!empty($this->useCabac) && $sliceType !== 2) {
            $bits .= $this->ue($this->cabacInitIdc);
        }
        if ($sliceType === 0) {
            $bits .= $this->se($this->pFrameQpDelta);
        } elseif ($savedQp !== null) {
            $bits .= $this->se($this->qp - $savedQp); // I8x8 QP 补偿（片头与宏块量化同源）
        } else {
            $bits .= $this->se(0);
        }

        // 去块滤波参数：disable_deblocking_filter_idc = 1 表示整片关闭
        // 禁用deblocking filter（编码器未实现去块滤波，需与解码器保持一致）
        $bits .= $this->ue(1);

        if (!empty($this->useCabac)) {
            // 片头字节对齐（alignment_bit_equal_to_one + 0 填充），CABAC 数据自字节边界开始
            $bits .= '1';
            while (strlen($bits) % 8 != 0) $bits .= '0';
            // 帧级簿记复位 + 引擎复位/上下文初始化（每帧单片，各调用一次）
            $this->encFrameBegin();
            // 第二个参数为 slice_type 相关的上下文初始化类别（P=0，I/B=2/1，规范 9.3.1.2）
            $this->encSliceBegin($this->qp, $sliceType === 0 ? 0 : 2);
        }

        // 上方宏块行的 nz 缓存：亮度每宏块 4 个 4x4 块，色度每宏块 2 个
        $topNzLuma = array_fill(0, $mbWidth * 4, 0);
        $topNzCb = array_fill(0, $mbWidth * 2, 0);
        $topNzCr = array_fill(0, $mbWidth * 2, 0);
        // 上方行的 I4x4 预测模式（-1 表示不可用），用于 I4x4 的 most_probable_mode 推导（规范 8.3.1.1）
        $topIntra4x4Mode = array_fill(0, $mbWidth * 4, -1);

        // P 片：保存 worker 返回的逐宏块搜索结果，供 encodePMacroblock 读取
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
            // 中性灰 128 初始化重建平面（解码端未覆盖区域通常也按 128 处理）
            $this->reconYPlane = str_repeat("\x80", $reconYSize);
            $this->reconUPlane = str_repeat("\x80", $reconUvSize);
            $this->reconVPlane = str_repeat("\x80", $reconUvSize);
            $this->reconPreassembled = false;
        }

        // CAVLC 下连续 P_Skip 宏块的游程计数（规范 7.3.4 的 mb_skip_run）
        $mbSkipRun = 0;
        // 是否 P 片
        $isPSlice = $sliceType === 0;

        // 严格光栅顺序遍历宏块（规范 6.4.1 的宏块地址递增顺序）
        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            // 每行起始：左侧不可用（片内无左邻）
            $leftAvailable = false;
            // 左邻 nz 缓存（亮度 4 + 色度 Cb 2 + 色度 Cr 2）
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            // 左邻 I4x4 模式缓存
            $leftIntra4x4Mode = [-1, -1, -1, -1];
            // 行首重置左列 MV 与左上 MV
            $this->mvLeftCol = [null, null, null, null];
            $this->mvTopLeft = null;
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                // 预取「下一宏块的左上邻」= 上方行该宏块最右列的 MV（处理完当前宏块后左移为左上）
                $nextMvTopLeft = $this->mvTopRow[$mbX * 4 + 3] ?? null;
                if ($isPSlice) {
                    // P 片：编码 P 宏块（内部读取 motionWorkerResults 做 MV 择优与残差）
                    $mbBits = $this->encodePMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode,
                        $this->refYPlane
                    );
                    if (!empty($this->useCabac)) {
                        // CABAC：mb_skip_flag 已在 encodePMacroblock 内逐宏块写入（返回恒空串）
                    } elseif ($this->lastMbWasSkip) {
                        // CAVLC：跳过宏块先只累计游程，等遇到非跳过宏块时一次性写出
                        $mbSkipRun++;
                    } else {
                        // 写出累积的 mb_skip_run，再写当前宏块语法
                        $bits .= $this->ue($mbSkipRun);
                        $bits .= $mbBits;
                        $mbSkipRun = 0;
                    }
                } else {
                    // I 片：编码帧内宏块
                    $mbBits = $this->encodeMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode
                    );
                    $bits .= $mbBits;

                    // 帧内宏块没有运动矢量：MV 邻居缓存统一填 [0,0,-1]（refIdx=-1 表示帧内）
                    $intraMv = [0, 0, -1];
                    $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
                    $this->mvTopRow[$mbX * 4 + 0] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 1] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 2] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 3] = $intraMv;
                }
                // 当前宏块处理完，其上方最右列成为下一个宏块的左上邻
                $this->mvTopLeft = $nextMvTopLeft;
                $leftAvailable = true;
                // end_of_slice_flag：CABAC 每个宏块后必须写 terminate bin（解码端逐宏块读取，
                // 该 bin 会使 range-=2，漏写将导致算术引擎逐步失步）；末宏块由片尾 encTerminate(1) 承担
                if (!empty($this->useCabac) && !($mbX === $mbWidth - 1 && $mbY === $mbHeight - 1)) {
                    $this->encTerminate(0);
                }
            }
        }
        // 片尾：若最后一个宏块仍是跳过块，补写剩余的 mb_skip_run（规范 7.3.4）
        if ($isPSlice && $mbSkipRun > 0 && empty($this->useCabac)) {
            $bits .= $this->ue($mbSkipRun);
        }
        if (!empty($this->useCabac)) {
            // CABAC 片尾：terminate bin（end_of_slice_flag=1）+ flush，字节流拼在片头之后
            $this->encTerminate(1);
            $rbsp = $this->bitsToBytes($bits) . $this->encGetBytes();
        } else {
            // CAVLC 片尾：rbsp_slice_trailing_bits（stop bit=1 后补 0 对齐，规范 7.3.2.11）
            $bits .= '1';
            while (strlen($bits) % 8 != 0) $bits .= '0';
            $rbsp = $this->bitsToBytes($bits);
        }
        // IDR 片 nal_unit_type=5，非 IDR 片=1（规范表 7-1）
        $nalType = $isIDR ? 5 : 1;
        $nal = $this->rbspToNal($rbsp, $nalType);

        // 保存重建后的帧作为下一帧的参考帧（流水线 P 帧两者本就是同一内容）
        $this->refYPlane = $this->reconYPlane;
        $this->refUPlane = $this->reconUPlane;
        $this->refVPlane = $this->reconVPlane;
        // 参考帧整数缓存失效
        $this->refInts = null;
        // 清除两个 recon 预拼标志，避免影响下一帧
        $this->reconPreassembled = false;
        $this->reconPrefetched = false;

        // frame_num 自增（规范 7.4.3）；POC 按每帧 +2 递增（IBBP 显示序步进）
        $this->frameNum++;
        $this->poc += 2;

        // 追加切片 NAL
        $prefixNals[] = $nal;
        if ($savedQp !== null) {
            $this->qp = $savedQp; // 恢复 I8x8 QP 补偿前的原值
        }
        return $prefixNals;
    }

    /**
     * 编码一个 B 帧（IBBP 中的非参考 B 帧）。
     * 解码序：其后的锚点（未来帧）已编码完成，才编码它前面的 B 帧。
     * B 帧不更新参考帧、不自增 frameNum/poc，只产出 1 个 slice NAL（无 SPS/PPS）。
     *
     * @param string $yuvData 该 B 帧画面（YUV420p）
     * @param int $poc 该 B 帧 POC（IBBP 下 = 2 × 显示序），由驱动层按显示序给出
     * @param array|null $refL0 过去锚点重建帧 [y,u,v]；null 取 snapshotBRefL0() 保存的快照
     * @param array|null $refL1 未来锚点重建帧 [y,u,v]；null 取当前参考帧 $this->refYPlane/…
     * @return array 仅含该帧 slice NAL 的数组（与 encodeFrame 返回值形状一致）
     */
    public function encodeBFrame(string $yuvData, int $poc, ?array $refL0 = null, ?array $refL1 = null): array
    {
        // L0（过去锚点）缺省取 snapshotBRefL0() 保存的快照平面
        $refL0 ??= [$this->bRefL0YPlane, $this->bRefL0UPlane, $this->bRefL0VPlane];
        // L1（未来锚点）缺省取当前参考帧（即刚编码完的未来锚点重建帧）
        $refL1 ??= [$this->refYPlane, $this->refUPlane, $this->refVPlane];
        // B 片必须同时具备两个方向的参考
        if ($refL0[0] === null || $refL1[0] === null) {
            throw new \RuntimeException('B 帧需要 L0/L1 两个锚点参考帧');
        }
        return $this->encodeBSliceBody($yuvData, $poc, $refL0, $refL1);
    }

    /** B slice 头 + B 宏块 CAVLC 主循环（B_L0_16x16 / B_L1_16x16 按 SAD 择优） */
    private function encodeBSliceBody(string $yuvData, int $poc, array $refL0, array $refL1): array
    {
        // 平面宏块对齐填充，得到三分量平面与尺寸
        [$yPlane, $uPlane, $vPlane, $mbWidth, $mbHeight, $aw, $ah] = $this->padYuvPlanes($yuvData);
        // 宏块画布尺寸（CABAC 簿记按此分配）
        $this->picWidthInMbs = $mbWidth;
        $this->picHeightInMbs = $mbHeight; // CABAC 帧级簿记画布按此尺寸分配，漏设会导致 picMbType 全空
        $this->mbAlignedWidth = $aw;
        $this->mbAlignedHeight = $ah;

        $bits = '';
        // 片头前三个 ue(v)：first_mb_in_slice / slice_type / pic_parameter_set_id（规范 7.3.3）
        $bits .= $this->ue(0);   // first_mb_in_slice
        $bits .= $this->ue(1);   // slice_type = B
        $bits .= $this->ue(0);   // pic_parameter_set_id

        // frame_num 位宽 = log2_max_frame_num_minus4 + 4
        $log2MaxFrameNum = $this->log2MaxFrameNumMinus4 + 4;
        // 非参考帧：frame_num = 解码序中前一个参考帧的 frame_num。
        // 锚点编码结束时 frameNum 已自增，故此处取 frameNum-1。
        $bFrameNum = ($this->frameNum - 1) & ((1 << $log2MaxFrameNum) - 1);
        $bits .= $this->u($bFrameNum, $log2MaxFrameNum);

        // POC LSB 位宽 = log2_max_pic_order_cnt_lsb_minus4 + 4
        $log2MaxPicOrderCntLsb = $this->log2MaxPicOrderCntLsbMinus4 + 4;
        // 按位宽取模，保证不越界
        $pocLsb = $poc & ((1 << $log2MaxPicOrderCntLsb) - 1);
        $bits .= $this->u($pocLsb, $log2MaxPicOrderCntLsb);

        $bits .= '1';  // direct_spatial_mv_pred_flag（本编码器不使用 direct 模式，取值不影响解码）
        $bits .= '0';  // num_ref_idx_active_override_flag
        $bits .= '0';  // ref_pic_list_modification_flag_l0
        $bits .= '0';  // ref_pic_list_modification_flag_l1
        // CABAC：cabac_init_idc 必须写在 slice_qp_delta 之前（spec 7.3.3 顺序）
        if (!empty($this->useCabac)) {
            $bits .= $this->ue($this->cabacInitIdc);
        }
        $bits .= $this->se($this->pFrameQpDelta); // slice_qp_delta
        $bits .= $this->ue(1); // disable_deblocking_filter_idc = 1（与解码器一致）

        if (!empty($this->useCabac)) {
            // CABAC：片头字节对齐 + 引擎启动
            $bits .= '1';
            while (strlen($bits) % 8 != 0) $bits .= '0';
            $this->encFrameBegin();
            // B 片的上下文初始化类别为 1（规范 9.3.1.2）
            $this->encSliceBegin($this->qp, 1);
        }

        // 上方行 nz 缓存：亮度每宏块 4 个 4x4 块，色度每宏块 2 个
        $topNzLuma = array_fill(0, $mbWidth * 4, 0);
        $topNzCb = array_fill(0, $mbWidth * 2, 0);
        $topNzCr = array_fill(0, $mbWidth * 2, 0);

        // B 双 list 邻居缓存（镜像解码器：片起始顶部为 null，每行起始重置 left/topLeft）
        $this->bMvTopRow = [array_fill(0, $mbWidth * 4, null), array_fill(0, $mbWidth * 4, null)];
        $this->bMvLeftCol = [array_fill(0, 4, null), array_fill(0, 4, null)];
        $this->bMvTopLeft = [null, null];

        // 两个参考帧的 Y 整数缓存各 unpack 一次（每宏块复用），避免每宏块重建整帧数组
        $this->bRefIntsL0 = unpack('C*', $refL0[0]);
        $this->bRefIntsL1 = unpack('C*', $refL1[0]);

        // 色度行跨度
        $cw = intdiv($aw, 2);
        // 运动搜索半径（快速模式收紧到 8）
        $motionRange = $this->fastMotion ? min(8, $this->motionRange) : $this->motionRange;
        // 光栅顺序遍历宏块
        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            // 行首左邻不可用
            $leftAvailable = false;
            // 左邻 nz 缓存
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            // 行首重置双 list 的左列 MV 与左上 MV
            $this->bMvLeftCol = [array_fill(0, 4, null), array_fill(0, 4, null)];
            $this->bMvTopLeft = [null, null];
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                // 预取下一宏块的左上邻（双 list）
                $nextBTL0 = $this->bMvTopRow[0][$mbX * 4 + 3] ?? null;
                $nextBTL1 = $this->bMvTopRow[1][$mbX * 4 + 3] ?? null;

                // 从对齐后的平面中取出当前宏块的 16x16 亮度块
                $luma = '';
                for ($row = 0; $row < 16; $row++) {
                    $luma .= substr($yPlane, ($mbY * 16 + $row) * $aw + $mbX * 16, 16);
                }
                // 取出当前宏块的 8x8 U/V 块
                $curU = '';
                $curV = '';
                for ($row = 0; $row < 8; $row++) {
                    $curU .= substr($uPlane, ($mbY * 8 + $row) * $cw + $mbX * 8, 8);
                    $curV .= substr($vPlane, ($mbY * 8 + $row) * $cw + $mbX * 8, 8);
                }

                // 第一步：在 L0/L1 间做运动搜索与方向择优（规范 8.4 帧间预测）
                $prepared = $this->prepareBMacroblock(
                    $mbX, $mbY, $luma, $curU, $curV,
                    $refL0[0], $refL0[1], $refL0[2],
                    $refL1[0], $refL1[1], $refL1[2],
                    $motionRange
                );
                // 第二步：把预测结果写成 B 宏块语法（含残差 CAVLC）
                $bits .= $this->encodeBMacroblock(
                    $mbX, $mbY, $prepared,
                    $leftAvailable, $leftNz, $mbY > 0,
                    $topNzLuma, $topNzCb, $topNzCr
                );

                // 更新左上邻，供下一宏块的 MVP 推导使用（规范 8.4.1.3）
                $this->bMvTopLeft[0] = $nextBTL0;
                $this->bMvTopLeft[1] = $nextBTL1;
                $leftAvailable = true;
                // end_of_slice_flag：CABAC 每个宏块后必须写 terminate bin；末宏块由片尾 encTerminate(1) 承担
                if (!empty($this->useCabac) && !($mbX === $mbWidth - 1 && $mbY === $mbHeight - 1)) {
                    $this->encTerminate(0);
                }
            }
        }
        if (!empty($this->useCabac)) {
            // CABAC 片尾：terminate bin + flush，字节流拼在片头之后
            $this->encTerminate(1);
            $rbsp = $this->bitsToBytes($bits) . $this->encGetBytes();
        } else {
            // CAVLC 片尾：rbsp_slice_trailing_bits（stop bit=1 后补 0 对齐，规范 7.3.2.11）
            $bits .= '1';
            while (strlen($bits) % 8 != 0) $bits .= '0';
            $rbsp = $this->bitsToBytes($bits);
        }

        // B 片为非参考帧：nal_ref_idc 必须为 0（规范 7.3.1）
        $nal = $this->rbspToNal($rbsp, 1, 0); // B 片：nal_ref_idc = 0
        return [$nal];
    }
}
