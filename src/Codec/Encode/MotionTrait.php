<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 运动矢量缓存与参考帧管理
 * @author yanglong
 * 本 trait 承担编码器的运动估计（ME）与运动补偿（MC）取像素职责：
 * 以 16x16 宏块为单位，在参考帧亮度平面上做整像素菱形搜索（LDSP/SDSP），
 * 再按 H.264 8.4.2.2 与 8.4.2.2.1 的 1/2、1/4 像素插值做亚像素细化，
 * 输出 1/4 像素精度的 MV 与对应的 SAD（匹配代价）。
 * 参考帧亮度平面按 mbAlignedWidth 对齐存储，stride = mbAlignedWidth，像素为 8bit。
 */
trait MotionTrait
{

    // 依赖宿主编码器类（H264Encoder）提供的成员：
    //   $mbAlignedWidth / $mbAlignedHeight —— 参考帧亮度平面的对齐 stride 与高度；
    //   $width / $height —— 当前帧有效尺寸，用于边缘宏块裁剪；
    //   $refInts —— 参考帧平面 unpack('C*') 后的整型像素缓存（1 基下标）；
    //   $qp / $earlySkip —— 量化参数与 Tier2 亚像素跳过开关。
    // 本 trait 只做取像素、插值、SAD 与搜索，不负责写码流。
    /**
     * 获取参考帧中指定位置的像素值（带边界处理）
     * 使用mbAlignedWidth/mbAlignedHeight作为stride，与参考帧存储格式一致
     * @param string $refPlane 参考帧亮度平面（按 mbAlignedWidth 对齐的 8bit 原始字节串）
     * @param int $x 水平整像素坐标（可为负，越界将被钳制）
     * @param int $y 垂直整像素坐标（可为负，越界将被钳制）
     * @return int 像素值 0..255
     * 边界处理依据 H.264 8.4.2：插值所需的参考像素超出图像时按边界像素复制，
     * 因此这里对 x/y 分别 clamp 到存储范围，等效于 8.4.2 的边界扩展语义。
     */
    private function getRefPixel(string $refPlane, int $x, int $y): int
    {
        // stride 取宏块对齐宽度：参考帧每行实际存放 mbAlignedWidth 个像素
        $stride = $this->mbAlignedWidth;
        // 水平方向钳制到 [0, stride-1]，越界取边界像素（H.264 8.4.2 边界扩展）
        $x = max(0, min($stride - 1, $x));
        // 垂直方向钳制到 [0, mbAlignedHeight-1]，同样为边界像素复制
        $y = max(0, min($this->mbAlignedHeight - 1, $y));
        // 行主序寻址：index = y * stride + x；ord() 取出无符号 8bit 像素值
        return ord($refPlane[$y * $stride + $x]);
    }

    /**
     * 6抽头水平插值（计算半像素b）
     * b[i,j] = (E - 5F + 20G + 20H - 5I + J + 16) >> 5
     * 对应 H.264 8.4.2.2.1 的 1/2 亮度样点插值：沿水平方向对 6 个相邻整数像素做 6 抽头 FIR，
     * 系数 (1, -5, 20, 20, -5, 1) 即 b 位置（水平半像素）所用滤波器。
     * 中间结果加 16 后右移 5（÷32）完成四舍五入，并按 8.4.2.2.1 的 clip1 饱和到 0..255。
     * @param string $refPlane 参考帧亮度平面
     * @param int $x 目标位置对应的整像素基准 x（取 x-2..x+3 六个像素）
     * @param int $y 整像素行号
     * @return int 插值结果（已 clip 到 0..255）
     */
    private function interpHorizontal(string $refPlane, int $x, int $y): int
    {
        // 取水平 6 抽头：E..J 依次为 x-2、x-1、x、x+1、x+2、x+3 的整数像素
        $E = $this->getRefPixel($refPlane, $x - 2, $y);
        $F = $this->getRefPixel($refPlane, $x - 1, $y);
        $G = $this->getRefPixel($refPlane, $x,     $y);
        $H = $this->getRefPixel($refPlane, $x + 1, $y);
        $I = $this->getRefPixel($refPlane, $x + 2, $y);
        $J = $this->getRefPixel($refPlane, $x + 3, $y);
        // 6 抽头加权求和，系数见 INTERP_TAP0..INTERP_TAP5（1,-5,20,20,-5,1）
        $val = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
            + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        // +16 做四舍五入后 >>5，再饱和到 [0,255]（8.4.2.2.1 的 clip1）
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头垂直插值（计算半像素h）
     * 对应 H.264 8.4.2.2.1 的 h 位置（垂直半像素）：
     * 与水平插值共用同一组 6 抽头系数 (1,-5,20,20,-5,1)，只是沿列方向取 6 个整数像素。
     * @param string $refPlane 参考帧亮度平面
     * @param int $x 整像素列号
     * @param int $y 目标位置对应的整像素基准 y（取 y-2..y+3）
     * @return int 插值结果（已 clip 到 0..255）
     */
    private function interpVertical(string $refPlane, int $x, int $y): int
    {
        // 取垂直 6 抽头：A..F 依次为 y-2、y-1、y、y+1、y+2、y+3 同一列的整数像素
        $A = $this->getRefPixel($refPlane, $x, $y - 2);
        $B = $this->getRefPixel($refPlane, $x, $y - 1);
        $C = $this->getRefPixel($refPlane, $x, $y);
        $D = $this->getRefPixel($refPlane, $x, $y + 1);
        $E = $this->getRefPixel($refPlane, $x, $y + 2);
        $F = $this->getRefPixel($refPlane, $x, $y + 3);
        // 垂直方向 6 抽头加权求和（系数同 b 位置）
        $val = self::INTERP_TAP0 * $A + self::INTERP_TAP1 * $B + self::INTERP_TAP2 * $C
            + self::INTERP_TAP3 * $D + self::INTERP_TAP4 * $E + self::INTERP_TAP5 * $F;
        // +16 四舍五入后 >>5，再饱和到 [0,255]
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头对角插值（计算半像素j）
     * 先水平后垂直，或先垂直后水平
     * 对应 H.264 8.4.2.2.1 的 j 位置（水平、垂直均为半像素）：
     * 规范允许先水平后垂直或反之，本实现采用水平→垂直的两次一维滤波。
     * @param string $refPlane 参考帧亮度平面
     * @param int $x 目标位置对应的整像素基准 x
     * @param int $y 目标位置对应的整像素基准 y
     * @return int 插值结果（已 clip 到 0..255）
     */
    private function interpDiagonal(string $refPlane, int $x, int $y): int
    {
        // 先做水平插值，得到中间值aa, bb, ..., ff（不clip）
        $vals = [];
        // 逐行（y-2..y+3）做水平 6 抽头，得到 6 个未 clip 的中间值
        for ($dy = -2; $dy <= 3; $dy++) {
            $E = $this->getRefPixel($refPlane, $x - 2, $y + $dy);
            $F = $this->getRefPixel($refPlane, $x - 1, $y + $dy);
            $G = $this->getRefPixel($refPlane, $x,     $y + $dy);
            $H = $this->getRefPixel($refPlane, $x + 1, $y + $dy);
            $I = $this->getRefPixel($refPlane, $x + 2, $y + $dy);
            $J = $this->getRefPixel($refPlane, $x + 3, $y + $dy);
            // 行内水平 6 抽头 (1,-5,20,20,-5,1)，暂不 clip 以保留精度
            $vals[] = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
                + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        }
        // 对中间值做垂直插值
        $val = self::INTERP_TAP0 * $vals[0] + self::INTERP_TAP1 * $vals[1] + self::INTERP_TAP2 * $vals[2]
            + self::INTERP_TAP3 * $vals[3] + self::INTERP_TAP4 * $vals[4] + self::INTERP_TAP5 * $vals[5];
        // 两级滤波总增益 32×32=1024：+512 后 >>10 完成两级四舍五入，再 clip 到 [0,255]
        return max(0, min(255, (($val + 512) >> 10)));
    }

    /**
     * 获取参考块（支持半像素位置）
     * 按 H.264 8.4.2.2 运动补偿流程：由 1/2 像素坐标的奇偶选择整数像素、
     * 水平半像素 b、垂直半像素 h 或对角半像素 j 的插值结果。
     * @param string $refPlane 参考帧亮度平面
     * @param int $qpX X位置（1/2像素单位，即qpX=2表示1像素，qpX=3表示1.5像素）
     * @param int $qpY Y位置（1/2像素单位）
     * @return array 16x16 预测块（$block[y][x]）
     */
    private function getReferenceBlock(string $refPlane, int $qpX, int $qpY): array
    {
        // 预测块 16x16，下标 [行][列]，初值 0
        $block = array_fill(0, 16, array_fill(0, 16, 0));
        // qpX 以 1/2 像素为单位：>>1 得整像素基准
        $intX = $qpX >> 1;
        $intY = $qpY >> 1;
        // &1 得半像素相位：0 与整数像素对齐，1 为半像素位置
        $halfX = $qpX & 1;
        $halfY = $qpY & 1;

        // 逐样点生成运动补偿预测值（H.264 8.4.2.2），整块共用同一相位
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                // 当前预测样点对应的整像素坐标
                $px = $intX + $x;
                $py = $intY + $y;
                if ($halfX == 0 && $halfY == 0) {
                    // 整数像素
                    $block[$y][$x] = $this->getRefPixel($refPlane, $px, $py);
                } elseif ($halfX == 1 && $halfY == 0) {
                    // 水平半像素 b
                    $block[$y][$x] = $this->interpHorizontal($refPlane, $px, $py);
                } elseif ($halfX == 0 && $halfY == 1) {
                    // 垂直半像素 h
                    $block[$y][$x] = $this->interpVertical($refPlane, $px, $py);
                } else {
                    // 对角半像素 j
                    $block[$y][$x] = $this->interpDiagonal($refPlane, $px, $py);
                }
            }
        }
        // 返回完整预测块，用于 SAD 比较或最终运动补偿重建
        return $block;
    }

    /**
     * 计算两个16x16块的SAD
     * SAD = Σ|cur - ref|，是运动估计的匹配代价（率失真前的失真项），
     * 本实现按 16x16 全部样点求和，不做像素加权。
     * @param array $block1 当前块（$block[y][x]）
     * @param array $block2 参考/预测块（$block[y][x]）
     * @return int 256 个样点的绝对差之和
     */
    private function computeSAD(array $block1, array $block2): int
    {
        $sad = 0;
        // 累加 16x16 共 256 个样点的绝对差
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                // 逐样点累加 |cur - ref|
                $sad += abs($block1[$y][$x] - $block2[$y][$x]);
            }
        }
        return $sad;
    }

    /**
     * 运动估计：整数像素搜索，菱形搜索
     * 策略：8 点大菱形（LDSP）迭代 + 4 点小菱形（SDSP）收敛，再做 1/2、1/4 像素细化；
     * 候选 SAD 以当前最优值为 cutoff 提前截断，结果与完整计算一致。
     * @param array $curFlat 当前宏块亮度像素（0基、长度256）
     * @param string $refPlane 参考帧亮度平面（mbAlignedWidth 对齐）
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $searchRange 整像素搜索半径（默认 16，边缘会被参考帧边界收缩）
     * 输出 MV 为 1/4 像素单位，与 H.264 8.4.1.3/8.4.1.4 的 MV 预测/差分单位一致。
     * @return array [mvX, mvY, sad] 运动向量和SAD值（mvX/mvY为1/4像素单位）
     */
    public function motionEstimate16x16(array $curFlat, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        if (!isset($this->refInts) || $this->refInts === null) {
            // 参考帧惰性展开为 1 基整型数组，避免逐像素调用 ord()
            $this->refInts = unpack('C*', $refPlane);
        }

        // 宏块左上角在参考帧中的整像素坐标
        $origX = $mbX * 16;
        $origY = $mbY * 16;
        // 有效块宽高：末列/末行宏块可能不足 16x16
        $blockW = min(16, $this->width - $origX);
        $blockH = min(16, $this->height - $origY);
        // 参考帧 stride 与存储格式一致（mbAlignedWidth）
        $refStride = $this->mbAlignedWidth;

        // 搜索范围同时受 searchRange 与参考帧边界约束：H.264 8.4.2 允许越界参考像素
        // 按边界复制，这里改为直接收缩候选 MV 范围（等效），并用 -blockW/-blockH 保证整块可读。
        $minDx = max(-$searchRange, -$origX);
        $maxDx = min($searchRange, $this->mbAlignedWidth - $origX - $blockW);
        $minDy = max(-$searchRange, -$origY);
        $maxDy = min($searchRange, $this->mbAlignedHeight - $origY - $blockH);

        // 模板点预展开为 static 平面数组（顺序与原嵌套数组逐项一致），候选键改整数
        // key=(dy-minDy)*spanX+(dx-minDx)（在钳制范围内单射），消除每调用的模式数组分配与字符串拼接。
        static $ldspX = [-2, 2, 0, 0, -1, 1, -1, 1];
        static $ldspY = [0, 0, -2, 2, -1, -1, 1, 1];
        static $sdspX = [-1, 1, 0, 0];
        static $sdspY = [0, 0, -1, 1];
        $spanX = $maxDx - $minDx + 1;

        // 初始 MV 取 (0,0)，即假设当前帧与参考帧静止（P_Skip 的零 MV 语义见 8.4.1.3/8.4.1.4）
        $bestDX = 0;
        $bestDY = 0;
        // 零 MV 的整块 SAD 作为初始上界，也是后续所有候选的 cutoff
        $bestSAD = $this->computeSADFast($curFlat, $origX, $origY, 0, 0, $blockW, $blockH, $refStride, PHP_INT_MAX);
        // 候选 SAD 缓存避免菱形搜索多轮重复计算；
        // 键 =(dy-minDy)*spanX+(dx-minDx)，在钳制矩形内单射
        $candidateSads = [(0 - $minDy) * $spanX + (0 - $minDx) => $bestSAD];

        // LDSP 轮数上限：小窗 4 轮、大窗 10 轮；某轮无改进即提前退出
        $largeIterations = $searchRange <= 8 ? 4 : 10;
        for ($iter = 0; $iter < $largeIterations; $iter++) {
            $foundBetter = false;
            // 每轮检查大菱形 8 个邻居（轴距 2，对角偏移 (±1,±1)）
            for ($i = 0; $i < 8; $i++) {
                $dx = $bestDX + $ldspX[$i];
                $dy = $bestDY + $ldspY[$i];
                // 候选超出钳制范围则跳过（贴边时搜索窗被裁剪）
                if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                    continue;
                }
                $candidateKey = ($dy - $minDy) * $spanX + ($dx - $minDx);
                // 先查缓存：同一候选只算一次 SAD
                if (isset($candidateSads[$candidateKey])) {
                    $sad = $candidateSads[$candidateKey];
                } else {
                    // 小搜索窗：先用隔行隔列的 1/4 采样粗估 SAD
                    if ($searchRange <= 8) {
                        $rough = $this->computeSADRough($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                        // 以 rough*4 作为整块 SAD 的快速估计；已 ≥ bestSAD 时记入缓存并跳过精确计算
                        if ($rough * 4 >= $bestSAD) {
                            $candidateSads[$candidateKey] = $rough * 4;
                            continue;
                        }
                    }
                    // 精确 SAD，并以当前 bestSAD 作为 cutoff 提前截断
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride, $bestSAD);
                    $candidateSads[$candidateKey] = $sad;
                }
                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                    $foundBetter = true;
                }
            }
            // 本轮 8 邻居均无改进：LDSP 收敛
            if (!$foundBetter) break;
        }

        $smallIterations = $searchRange <= 8 ? 1 : 3;
        // SDSP 收敛：小窗 1 轮、大窗最多 3 轮，在 LDSP 最优点的 4 邻域内细搜
        for ($iter = 0; $iter < $smallIterations; $iter++) {
            $foundBetter = false;
            for ($i = 0; $i < 4; $i++) {
                $dx = $bestDX + $sdspX[$i];
                $dy = $bestDY + $sdspY[$i];
                if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                    continue;
                }
                $candidateKey = ($dy - $minDy) * $spanX + ($dx - $minDx);
                // 与 LDSP 相同的缓存/粗估策略，避免重复计算
                if (isset($candidateSads[$candidateKey])) {
                    $sad = $candidateSads[$candidateKey];
                } else {
                    if ($searchRange <= 8) {
                        $rough = $this->computeSADRough($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                        if ($rough * 4 >= $bestSAD) {
                            $candidateSads[$candidateKey] = $rough * 4;
                            continue;
                        }
                    }
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride, $bestSAD);
                    $candidateSads[$candidateKey] = $sad;
                }
                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                    $foundBetter = true;
                }
            }
            // 4 邻域无改进：SDSP 收敛
            if (!$foundBetter) break;
        }

        // === 半像素/1/4像素精搜索 ===
        // 使用mcLumaBlock计算子像素位置的SAD
        //$bestMVx = $bestDX * 4;
        //$bestMVy = $bestDY * 4;
        // 整数 MV 的 MC 像素与整数 SAD 候选完全等价，复用已计算结果。
        // 用缓存中与最优整像素 MV 对应的 SAD 覆盖 bestSAD，保证返回值与 MV 自洽
        $bestSAD = $candidateSads[($bestDY - $minDy) * $spanX + ($bestDX - $minDx)];

        // Tier2 early-skip：整数最优停在 (0,0) 且 SAD 落入量化死区量级时，
        // 跳过 6 抽头插值缓冲与半/四像素精搜（DCT 仍照常，结果合法）。
        // 从编码代价看，SKIP 模式无需传 MV 与残差；零 MV 且残差落入量化死区时，
        // 继续付出插值代价寻找亚像素 MV 的收益可忽略（P_Skip 见 H.264 8.4.1.3/8.4.1.4）。
        if ($this->earlySkip && $bestDX === 0 && $bestDY === 0 && $bestSAD <= self::subpelSkipSad($this->qp)) {
            // 直接以 MV=(0,0) 返回，相当于走 SKIP 的最省代价路径
            return [0, 0, $bestSAD];
        }
        // 小搜索窗且残差很小：只保留整像素 MV，省去 6 抽头插值与 1/4 像素搜索
        if ($searchRange <= 8 && $bestSAD <= (self::subpelSkipSad($this->qp) * 3)) {
            return [$bestDX * 4, $bestDY * 4, $bestSAD];
        }

        // 亚像素细化（H.264 8.4.2.2.1）：先半像素轴向/对角候选，再 1/4 像素候选
        [$bestMVx, $bestMVy, $bestSAD] = $this->refineSubpelShared(
            $curFlat, $origX, $origY, $bestDX, $bestDY, $bestSAD, $blockW, $blockH,
            $minDx, $maxDx, $minDy, $maxDy, $searchRange <= 8
        );

        // 返回 1/4 像素单位 MV 与最终 SAD
        return [$bestMVx, $bestMVy, $bestSAD];
    }

    /**
     * 亚像素细化：在整像素最优点周围评估 1/2 与 1/4 像素候选
     *
     * 按 H.264 8.4.2.2.1 预先计算当前宏块所需的半像素缓冲区：
     *   h —— 水平 6 抽头结果（水平半像素 b 位置）
     *   v —— 垂直 6 抽头结果（垂直半像素 h 位置）
     *   c —— 先水平后垂直的对角半像素（j 位置）
     * 之后每个候选只需按偏移索引缓冲区求 SAD，避免重复插值与构造完整预测块。
     *
     * @param array $curFlat 当前宏块亮度像素（按 16 列存储，0 基，长度 256）
     * @param int $origX 宏块在参考帧中的整像素原点 x
     * @param int $origY 宏块在参考帧中的整像素原点 y
     * @param int $bestDX 整像素最优 MV 的 x（整像素单位）
     * @param int $bestDY 整像素最优 MV 的 y（整像素单位）
     * @param int $bestSAD 整像素最优 SAD，作为亚像素候选的 cutoff
     * @param int $blockW 有效块宽
     * @param int $blockH 有效块高
     * @param int $minDx 候选 MV 的 x 下界（边界裁剪）
     * @param int $maxDx 候选 MV 的 x 上界
     * @param int $minDy 候选 MV 的 y 下界
     * @param int $maxDy 候选 MV 的 y 上界
     * @param bool $skipQuarter true 时只评估轴向半像素候选（小搜索窗快速路径）
     * @return array [mvX, mvY, sad]，MV 为 1/4 像素单位
     */
    private function refineSubpelShared(
        array $curFlat, int $origX, int $origY, int $bestDX, int $bestDY, int $bestSAD,
        int $blockW, int $blockH, int $minDx, int $maxDx, int $minDy, int $maxDy,
        bool $skipQuarter = false
    ): array {
        // 参考帧 stride/高度与整型像素缓存（1 基下标）
        $stride = $this->mbAlignedWidth;
        $height = $this->mbAlignedHeight;
        $ref = $this->refInts;
        // 整像素最优点在参考帧中的绝对坐标
        $baseX = $origX + $bestDX;
        $baseY = $origY + $bestDY;
        // 半像素缓冲区每行宽度：块宽 + 左右各 6 像素（6 抽头与细化所需余量）
        $bufW = $blockW + 12;

        // 坐标映射表 $xs/$ys：下标 = 相对偏移 + 8，值为钳制后的绝对坐标
        $xs = [];
        $ys = [];
        // 同一 stride/基准/块尺寸的映射表可跨宏块复用
        static $spCache = [];
        $spKey = "sp{$stride},{$height},{$baseX},{$baseY},{$blockW},{$blockH}";
        if (isset($spCache[$spKey])) {
            $xs = $spCache[$spKey]['xs'];
            $ys = $spCache[$spKey]['ys'];
        } else {
            for ($x = -8; $x <= $blockW + 8; $x++) {
                $rx = $baseX + $x;
                // 水平越界按边界像素复制（H.264 8.4.2）
                $xs[$x + 8] = $rx < 0 ? 0 : ($rx >= $stride ? $stride - 1 : $rx);
            }
            for ($y = -8; $y <= $blockH + 8; $y++) {
                $ry = $baseY + $y;
                // 垂直越界同理
                $ys[$y + 8] = $ry < 0 ? 0 : ($ry >= $height ? $height - 1 : $ry);
            }
            $spCache[$spKey] = ['xs' => $xs, 'ys' => $ys];
        }

        // h 为 clip 后的半像素值；hFull 为未 clip 的 6 抽头中间值（仅对角 c 需要）
        $hFull = $skipQuarter ? null : [];
        $h = [];
        // 缓冲区范围由 skipQuarter 决定：false 时向外扩 ±8 行、±6 列
        $hMinY = $skipQuarter ? -1 : -8;
        $hMaxY = $skipQuarter ? $blockH - 1 : $blockH + 8;
        $hMinX = $skipQuarter ? -1 : -6;
        $hMaxX = $skipQuarter ? $blockW - 1 : $blockW + 5;
        if ($skipQuarter) {
            // 半像素轴向模式：无 hFull 需求、y∈[-1,blockH-1] 全落在存储界内，去掉逐像素分支
            for ($y = -1; $y <= $blockH - 1; $y++) {
                // 行基址：钳制后的行 × stride，+1 抵消 refInts 的 1 基下标
                $row = $ys[$y + 8] * $stride + 1;
                // h 的写行基址
                $hRow = ($y + 6) * $bufW;
                for ($x = -1; $x <= $blockW - 1; $x++) {
                    $xi = $x + 8;
                    // 水平 6 抽头 (1,-5,20,20,-5,1)，中间值 full 不 clip
                    $full = $ref[$row + $xs[$xi - 2]] - 5 * $ref[$row + $xs[$xi - 1]]
                        + 20 * $ref[$row + $xs[$xi]] + 20 * $ref[$row + $xs[$xi + 1]]
                        - 5 * $ref[$row + $xs[$xi + 2]] + $ref[$row + $xs[$xi + 3]];
                    // +16>>5 得半像素值
                    $half = ($full + 16) >> 5;
                    // 饱和到 [0,255]（8.4.2.2.1 的 clip1）后写入 h
                    $h[$hRow + $x + 6] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                }
            }
        } else {
            for ($y = $hMinY; $y <= $hMaxY; $y++) {
                // 通用路径：行基址同样做 1 基补偿
                $row = $ys[$y + 8] * $stride + 1;
                for ($x = $hMinX; $x <= $hMaxX; $x++) {
                    $xi = $x + 8;
                    $full = $ref[$row + $xs[$xi - 2]] - 5 * $ref[$row + $xs[$xi - 1]]
                        + 20 * $ref[$row + $xs[$xi]] + 20 * $ref[$row + $xs[$xi + 1]]
                        - 5 * $ref[$row + $xs[$xi + 2]] + $ref[$row + $xs[$xi + 3]];
                    // 未 clip 中间值写入 hFull，供对角半像素 c 的垂直滤波
                    $hFull[($y + 8) * $bufW + $x + 6] = $full;
                    // 仅保存会被后续读取的行，减少无效计算
                    if ($y >= -6 && $y <= $blockH + 5) {
                        $half = ($full + 16) >> 5;
                        $h[($y + 6) * $bufW + $x + 6] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                    }
                }
            }
        }

        // v：对整数像素做垂直 6 抽头，得到垂直半像素 h 位置的值
        $v = [];
        // c：对角半像素 j 位置，先水平后垂直（总增益 1024）
        $c = $skipQuarter ? null : [];
        // skipQuarter（半像素轴向候选）下 v 的读集仅为 y∈[-1,blockH]、x∈[0,blockW-1]
        // （候选行 = y+offY+6, offY∈{-1,1}；候选列 = x+6, offX=0），其余条目从不被读取，裁剪不改结果。
        if ($skipQuarter) {
            $vMinY = -1; $vMaxY = $blockH;
            $vMinX = 0; $vMaxX = $blockW - 1;
        } else {
            $vMinY = -6; $vMaxY = $blockH + 5;
            $vMinX = -6; $vMaxX = $blockW + 5;
        }
        // 逐行逐列预计算 v（垂直半像素）与 c（对角半像素）
        for ($y = $vMinY; $y <= $vMaxY; $y++) {
            $yi = $y + 8;
            // 6 个抽头行的整型数组基址（已含 1 基补偿）
            $r0 = $ys[$yi - 2] * $stride + 1;
            $r1 = $ys[$yi - 1] * $stride + 1;
            $r2 = $ys[$yi] * $stride + 1;
            $r3 = $ys[$yi + 1] * $stride + 1;
            $r4 = $ys[$yi + 2] * $stride + 1;
            $r5 = $ys[$yi + 3] * $stride + 1;
            for ($x = $vMinX; $x <= $vMaxX; $x++) {
                // 输出索引：v 与 c 共用同一 (y,x) 网格
                $idx = ($y + 6) * $bufW + $x + 6;
                $rx = $xs[$x + 8];
                // 垂直 6 抽头 (1,-5,20,20,-5,1)
                $full = $ref[$r0 + $rx] - 5 * $ref[$r1 + $rx]
                    + 20 * $ref[$r2 + $rx] + 20 * $ref[$r3 + $rx]
                    - 5 * $ref[$r4 + $rx] + $ref[$r5 + $rx];
                // +16>>5 四舍五入
                $half = ($full + 16) >> 5;
                // clip 到 [0,255] 后写入 v
                $v[$idx] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);

                if (!$skipQuarter) {
                    $hf = ($y + 6) * $bufW + $x + 6;
                    // 对角：对 hFull 再做垂直 6 抽头，两级滤波共 32×32 增益
                    $full = $hFull[$hf] - 5 * $hFull[$hf + $bufW]
                        + 20 * $hFull[$hf + 2 * $bufW] + 20 * $hFull[$hf + 3 * $bufW]
                        - 5 * $hFull[$hf + 4 * $bufW] + $hFull[$hf + 5 * $bufW];
                    // +512>>10 完成两级四舍五入
                    $center = ($full + 512) >> 10;
                    // clip 到 [0,255] 后写入 c
                    $c[$idx] = $center < 0 ? 0 : ($center > 255 ? 255 : $center);
                }
            }
        }

        // 最优 MV 先取整像素最优点（1/4 像素单位 = 整像素 ×4）
        $bestMVx = $bestDX * 4;
        $bestMVy = $bestDY * 4;
        // 半像素候选偏移（1/4 像素单位）：±2 即半个像素；对角 (±2,±2) 为半像素对角
        $halfPattern = $skipQuarter ? [
            [-2, 0], [2, 0], [0, -2], [0, 2],
        ] : [
            [-2, -2], [-2, 0], [-2, 2],
            [ 0, -2],          [ 0, 2],
            [ 2, -2], [ 2, 0], [ 2, 2],
        ];
        // 依次评估各半像素候选，SAD 达到 cutoff 即放弃该候选
        foreach ($halfPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            // 1/4 像素 MV 拆成整像素基准 + 相位（fracX/fracY∈0..3）
            $dx = $mvx >> 2;
            $dy = $mvy >> 2;
            if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                continue;
            }
            // 小搜索窗只允许轴向半像素：frac 组合限定为相位 0 或 2
            $fracX = $mvx & 3;
            $fracY = $mvy & 3;
            if ($skipQuarter && $fracX !== 0 && $fracY !== 0) continue;
            // 相对整像素最优点的偏移（整像素单位），用于索引预计算缓冲区
            $offX = $dx - $bestDX;
            $offY = $dy - $bestDY;
            $sad = 0;
            $crossed = false;
            // fracX/fracY 每候选固定：按预测来源特化内层循环（h/v/整数/对角），
            // 截止判据移到行尾——候选 SAD 前缀和单调不减，行末 partial ≥ 截止值时
            // 该候选不可能胜出（胜出者必为完整精确 SAD），与逐像素 break 2 决策一致。
            // 水平半像素 b：查 h 缓冲区（8.4.2.2.1 的 b 位置）
            if ($fracX !== 0 && $fracY === 0) {
                for ($y = 0; $y < $blockH; $y++) {
                    $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                    $cur = $y * 16;
                    for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                        $diff = $curFlat[$cur] - $h[$bi];
                        $sad += $diff < 0 ? -$diff : $diff;
                    }
                    // 行末截断：partial ≥ bestSAD，该候选不可能胜出
                    if ($sad >= $bestSAD) { $crossed = true; break; }
                }
            } elseif ($fracX === 0 && $fracY !== 0) {
                // 垂直半像素 h：查 v 缓冲区（8.4.2.2.1 的 h 位置）
                for ($y = 0; $y < $blockH; $y++) {
                    $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                    $cur = $y * 16;
                    for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                        $diff = $curFlat[$cur] - $v[$bi];
                        $sad += $diff < 0 ? -$diff : $diff;
                    }
                    if ($sad >= $bestSAD) { $crossed = true; break; }
                }
            } elseif ($fracX === 0 && $fracY === 0) {
                // 整数像素：直接查参考帧（无插值）
                for ($y = 0; $y < $blockH; $y++) {
                    $ry = $ys[$y + $offY + 8] * $stride;
                    $xi = $offX + 8;
                    $cur = $y * 16;
                    for ($x = 0; $x < $blockW; $x++, $xi++, $cur++) {
                        $diff = $curFlat[$cur] - $ref[$ry + $xs[$xi] + 1];
                        $sad += $diff < 0 ? -$diff : $diff;
                    }
                    if ($sad >= $bestSAD) { $crossed = true; break; }
                }
            } else {
                // 对角半像素 j：查 c 缓冲区（8.4.2.2.1 的 j 位置）
                for ($y = 0; $y < $blockH; $y++) {
                    $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                    $cur = $y * 16;
                    for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                        $diff = $curFlat[$cur] - $c[$bi];
                        $sad += $diff < 0 ? -$diff : $diff;
                    }
                    if ($sad >= $bestSAD) { $crossed = true; break; }
                }
            }
            // 未被截断且确实更小：更新最优 MV 与 SAD
            if (!$crossed && $sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        if ($skipQuarter) {
            return [$bestMVx, $bestMVy, $bestSAD];
        }
        // 1/4 像素位置由相邻整数/半像素样点做四舍五入平均（8.4.2.2.1）：
        // frac 0=整数、1=整数与半像素均值、2=半像素、3=半像素与下一整数像素均值。
        $quarterPattern = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($quarterPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            $dx = $mvx >> 2;
            $dy = $mvy >> 2;
            if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                continue;
            }
            $fracX = $mvx & 3;
            $fracY = $mvy & 3;
            // offX/offY 用于定位缓存中的半像素值
            $offX = $dx - $bestDX;
            $offY = $dy - $bestDY;
            $sad = 0;
            for ($y = 0; $y < $blockH; $y++) {
                $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                $cur = $y * 16;
                for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                    // 整数像素样点：1/4 像素预测的基准值
                    $integer = $ref[$ys[$y + $offY + 8] * $stride + $xs[$x + $offX + 8] + 1];
                    if ($fracX === 0 && $fracY === 0) {
                        $pred = $integer;
                    } elseif ($fracY === 0) {
                        $half = $h[$bi];
                        $pred = $fracX === 1 ? (($integer + $half + 1) >> 1)
                            : ($fracX === 2 ? $half : (($half + $ref[$ys[$y + $offY + 8] * $stride + $xs[$x + $offX + 9] + 1] + 1) >> 1));
                    } elseif ($fracX === 0) {
                        $half = $v[$bi];
                        $pred = $fracY === 1 ? (($integer + $half + 1) >> 1)
                            : ($fracY === 2 ? $half : (($half + $ref[$ys[$y + $offY + 9] * $stride + $xs[$x + $offX + 8] + 1] + 1) >> 1));
                    } elseif ($fracX === 2) {
                        $center = $c[$bi];
                        $pred = $fracY === 2 ? $center
                            : (($h[$bi + ($fracY === 1 ? 0 : $bufW)] + $center + 1) >> 1);
                    } elseif ($fracY === 2) {
                        $pred = ($c[$bi] + $v[$bi + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    } else {
                        $pred = ($h[$bi + ($fracY === 1 ? 0 : $bufW)]
                            + $v[$bi + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    }
                    // 以上按 fracX/fracY 组合实现 1/4 像素位置的四舍五入平均
                    $diff = $curFlat[$cur] - $pred;
                    $sad += $diff < 0 ? -$diff : $diff;
                    // 逐像素截断：达到 cutoff 立即放弃该候选（跳出 x/y 两层循环）
                    if ($sad >= $bestSAD) break 2;
                }
            }
            // 完整计算且更小才更新最优
            if ($sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        // 返回 1/4 像素精度 MV 与最终 SAD
        return [$bestMVx, $bestMVy, $bestSAD];
    }

    /**
     * 计算子像素位置的SAD，融合运动补偿与像素比较，避免构造完整预测块。
     * 按 H.264 8.4.2.2.1 现场插值出预测样点并与当前块逐点求绝对差；
     * SAD 达到 cutoff 立即返回（前缀和单调不减，可安全提前退出）。
     * @param array $curFlat 当前宏块亮度像素（按 16 列存储）
     * @param string $refPlane 参考帧亮度平面
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mvx MV x 分量（1/4 像素单位，相对宏块原点）
     * @param int $mvy MV y 分量（1/4 像素单位）
     * @param int $blockW 有效块宽
     * @param int $blockH 有效块高
     * @param int $cutoff SAD 截断阈值（通常为当前最优 SAD）
     * @return int SAD；被截断时返回值 ≥ cutoff，仅用于判定不可能胜出
     */
    private function computeSADSubpel(array $curFlat, string $refPlane, int $mbX, int $mbY, int $mvx, int $mvy, int $blockW, int $blockH, int $cutoff): int
    {
        // 宏块原点换算到 1/4 像素坐标系：16 像素 × 4 + MV
        $refX = $mbX * 64 + $mvx;
        $refY = $mbY * 64 + $mvy;
        // >>2 取整像素基准，&3 取 1/4 像素相位
        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;
        // stride/height 与参考帧存储一致；refInts 为 1 基下标
        $stride = $this->mbAlignedWidth;
        $height = $this->mbAlignedHeight;
        $ref = $this->refInts;
        $sad = 0;

        // 坐标映射：偏移 -2..block+2 覆盖 6 抽头所需邻域（下标 = 偏移+2）
        $xs = [];
        $ys = [];
        // 同一基准/尺寸的映射表可复用
        static $coordCache = [];
        $cacheKey = "mc{$stride},{$height},{$intX},{$intY},{$blockW},{$blockH}";
        if (isset($coordCache[$cacheKey])) {
            $xs = $coordCache[$cacheKey]['xs'];
            $ys = $coordCache[$cacheKey]['ys'];
        } else {
            for ($x = -2; $x < $blockW + 3; $x++) {
                // 水平钳制到 [0,stride-1]（8.4.2 边界像素复制）
                $xs[$x + 2] = max(0, min($stride - 1, $intX + $x));
            }
            for ($y = -2; $y < $blockH + 3; $y++) {
                // 垂直钳制到 [0,height-1]
                $ys[$y + 2] = max(0, min($height - 1, $intY + $y));
            }
            $coordCache[$cacheKey] = ['xs' => $xs, 'ys' => $ys];
        }

        // 中间缓冲按相位裁剪：只在预测公式实际读取时才计算，减少多余滤波
        $h = [];
        // hFull 仅在需要 c 时使用
        $hFull = [];
        // c：对角半像素（含 c 的相位组合需要）
        $needC = ($fracX === 2 && $fracY !== 0) || ($fracY === 2 && $fracX !== 0);
        // h：水平半像素
        $needH = $fracX !== 0 && ($fracY === 0 || $fracX === 2 || ($fracX !== 2 && $fracY !== 2));
        if ($needH || $needC) {
            $startY = ($fracY === 0) ? 0 : -2;
            // fracY=0 只需块内行；否则扩 ±2 行满足垂直 6 抽头
            $rows = ($fracY === 0) ? $blockH : $blockH + 5;
            for ($y = 0; $y < $rows; $y++) {
                // 行基址（+1 为 refInts 的 1 基补偿）
                $base = $ys[$startY + $y + 2] * $stride + 1;
                for ($x = 0; $x < $blockW; $x++) {
                    // 水平 6 抽头 (1,-5,20,20,-5,1)，中间值不 clip
                    $full = $ref[$base + $xs[$x]] - 5 * $ref[$base + $xs[$x + 1]]
                        + 20 * $ref[$base + $xs[$x + 2]] + 20 * $ref[$base + $xs[$x + 3]]
                        - 5 * $ref[$base + $xs[$x + 4]] + $ref[$base + $xs[$x + 5]];
                    $idx = $y * $blockW + $x;
                    // 需要 c 时保留未 clip 中间值
                    if ($needC) $hFull[$idx] = $full;
                    // 需要 h 时四舍五入并 clip 到 [0,255]
                    if ($needH) {
                        $half = ($full + 16) >> 5;
                        $h[$idx] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                    }
                }
            }
        }

        $v = [];
        // v：垂直半像素；fracX≠0 时列数多 1（1/4 位置的平均需要右邻半像素）
        $needV = $fracY !== 0 && ($fracX === 0 || $fracY === 2 || ($fracX !== 2 && $fracY !== 2));
        $vCols = $blockW + (($fracX !== 0 && $needV) ? 1 : 0);
        if ($needV) {
            for ($y = 0; $y < $blockH; $y++) {
                for ($x = 0; $x < $vCols; $x++) {
                    $rx = $xs[$x + 2];
                    // 垂直 6 抽头，+16>>5 四舍五入后 clip
                    $full = $ref[$ys[$y] * $stride + $rx + 1]
                        - 5 * $ref[$ys[$y + 1] * $stride + $rx + 1]
                        + 20 * $ref[$ys[$y + 2] * $stride + $rx + 1]
                        + 20 * $ref[$ys[$y + 3] * $stride + $rx + 1]
                        - 5 * $ref[$ys[$y + 4] * $stride + $rx + 1]
                        + $ref[$ys[$y + 5] * $stride + $rx + 1];
                    $half = ($full + 16) >> 5;
                    $v[$y * $vCols + $x] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                }
            }
        }

        // 逐样点生成预测值并按相位分支；同时累加 SAD 并检查 cutoff
        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                // 相位 (0,0)：整数像素采样
                if ($fracX === 0 && $fracY === 0) {
                    $pred = $ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1];
                } elseif ($fracY === 0) {
                    $half = $h[$y * $blockW + $x];
                    if ($fracX === 1) {
                        $pred = ($ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1] + $half + 1) >> 1;
                    } elseif ($fracX === 2) {
                        $pred = $half;
                    } else {
                        $pred = ($half + $ref[$ys[$y + 2] * $stride + $xs[$x + 3] + 1] + 1) >> 1;
                    }
                } elseif ($fracX === 0) {
                    // 相位 (0,fracY)：垂直半像素或垂直方向的 1/4 位置
                    $half = $v[$y * $vCols + $x];
                    if ($fracY === 1) {
                        $pred = ($ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1] + $half + 1) >> 1;
                    } elseif ($fracY === 2) {
                        $pred = $half;
                    } else {
                        $pred = ($half + $ref[$ys[$y + 3] * $stride + $xs[$x + 2] + 1] + 1) >> 1;
                    }
                } elseif ($needC) {
                    // 需要 c 的相位：先水平后垂直得到对角半像素，再与 h/v 平均
                    $idx = $y * $blockW + $x;
                    $full = $hFull[$idx] - 5 * $hFull[$idx + $blockW]
                        + 20 * $hFull[$idx + 2 * $blockW] + 20 * $hFull[$idx + 3 * $blockW]
                        - 5 * $hFull[$idx + 4 * $blockW] + $hFull[$idx + 5 * $blockW];
                    $center = ($full + 512) >> 10;
                    $center = $center < 0 ? 0 : ($center > 255 ? 255 : $center);
                    if ($fracX === 2) {
                        $pred = $fracY === 2 ? $center : (($h[($y + ($fracY === 1 ? 2 : 3)) * $blockW + $x] + $center + 1) >> 1);
                    } else {
                        $pred = ($center + $v[$y * $vCols + $x + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    }
                } else {
                    // 其余相位：水平半像素与垂直半像素做四舍五入平均
                    $hIdx = $y + ($fracY === 1 ? 2 : 3);
                    $vIdx = $x + ($fracX === 3 ? 1 : 0);
                    $pred = ($h[$hIdx * $blockW + $x] + $v[$y * $vCols + $vIdx] + 1) >> 1;
                }

                // 绝对差累加；达到 cutoff 立即返回（该候选必不胜出）
                $diff = $curFlat[$y * 16 + $x] - $pred;
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
                if ($sad >= $cutoff) {
                    return $sad;
                }
            }
        }
        // 未被截断：返回完整子像素 SAD
        return $sad;
    }

    /**
     * 粗略 SAD：隔行隔列（约 1/4 样点）采样，用于小搜索窗的快速候选剪枝。
     *
     * 返回值为降采样绝对差和，调用方以 rough*4 估计完整 SAD 量级；
     * 若估计值已 ≥ 当前最优 SAD，则跳过该候选的完整计算。
     *
     * @param array $curFlat 当前宏块亮度像素（按 16 列存储）
     * @param int $origX 宏块整像素原点 x
     * @param int $origY 宏块整像素原点 y
     * @param int $dx 候选 MV 的 x 分量（整像素）
     * @param int $dy 候选 MV 的 y 分量（整像素）
     * @param int $blockW 有效块宽
     * @param int $blockH 有效块高
     * @param int $refStride 参考帧 stride
     * @return int 降采样绝对差和（约为完整 SAD 的 1/4）
     */
    private function computeSADRough(array $curFlat, int $origX, int $origY, int $dx, int $dy, int $blockW, int $blockH, int $refStride): int
    {
        // 候选块左上角在整型数组中的 1 基下标
        $refStart = ($origY + $dy) * $refStride + $origX + $dx + 1;
        $sad = 0;
        $refInts = $this->refInts;
        // 行、列均以步长 2 采样，约 1/4 样点
        for ($y = 0; $y < $blockH; $y += 2) {
            $ci = $y * 16;
            $ri = $refStart + $y * $refStride;
            for ($x = 0; $x < $blockW; $x += 2) {
                // 隔行隔列的绝对差累加
                $diff = $curFlat[$ci + $x] - $refInts[$ri + $x];
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
            }
        }
        // 返回降采样 SAD（调用方需 ×4 还原量级）
        return $sad;
    }

    /**
     * 快速整像素 SAD：按 8 列一批累加绝对差，并做前缀和提前截断。
     *
     * 每批结束检查是否达到 cutoff；达到即返回。SAD 前缀和单调不减，
     * 因此被截断候选的返回值必然 ≥ cutoff，不可能成为最终最优
     * （胜出候选一定会被完整计算），搜索决策与逐像素判断一致。
     *
     * @param array $curFlat 当前宏块亮度像素（按 16 列存储）
     * @param int $origX 宏块整像素原点 x
     * @param int $origY 宏块整像素原点 y
     * @param int $dx 候选 MV 的 x 分量（整像素）
     * @param int $dy 候选 MV 的 y 分量（整像素）
     * @param int $blockW 有效块宽
     * @param int $blockH 有效块高
     * @param int $refStride 参考帧 stride
     * @param int $cutoff SAD 截断阈值
     * @return int SAD（可能被提前截断）
     */
    private function computeSADFast(array $curFlat, int $origX, int $origY, int $dx, int $dy, int $blockW, int $blockH, int $refStride, int $cutoff): int
    {
        // 候选块左上角的整像素坐标
        $rx = $origX + $dx;
        $ry = $origY + $dy;
        // 整型数组 1 基下标（+1 为 unpack('C*') 补偿）
        $refStart = $ry * $refStride + $rx + 1;
        $sad = 0;
        $refInts = $this->refInts;

        // 8 宽分块批处理 + 游标递增：SAD 前缀和单调不减，批内任一点触发截止时
        // 批末 partial ≥ 截止值，该候选仍不可能胜出（胜出者必为完整计算、逐位精确），
        // 故搜索决策与返回的胜出 SAD 与逐像素截止完全一致。
        for ($y = 0; $y < $blockH; $y++) {
            // 当前行在 curFlat 与参考帧中的起点
            $ci = $y * 16;
            $ri = $refStart + $y * $refStride;
            $x = 0;
            // 按 8 列一批推进：批内累加，批末检查 cutoff
            while ($x < $blockW) {
                // 批末列号（末批可能不足 8 列）
                $end = ($x + 8 <= $blockW) ? $x + 8 : $blockW;
                // 批内逐像素累加，游标 ci/ri 同步前移
                do {
                    $diff = $curFlat[$ci] - $refInts[$ri];
                    $sad += $diff < 0 ? -$diff : $diff;
                    $ci++;
                    $ri++;
                } while (++$x < $end);
                // 批末截断：前缀和 ≥ cutoff，该候选不可能胜出
                if ($sad >= $cutoff) {
                    return $sad;
                }
            }
        }
        // 未被截断：返回完整整像素 SAD
        return $sad;
    }

    /**
     * Tier1 阈值：单个 4x4 残差块的 SAD 上限。
     * 块 SAD ≤ 返回值时，经 4x4 整数变换 + Inter 量化后 16 个系数必定全部为 0（数学保证）。
     * 推导：本次整数变换各输出系数对输入块 SAD 的 l1 放大上限为 norm[k]*norm[l]，
     * norm = [1,6,4,6]；量化为 0 的充要条件为 (FF+|coeff|)*MF ≤ 65535。
     * 取 16 个系数位置的最小值，故与 DCT 实际结果严格一致，无画质风险。
     * 该阈值同时是 SKIP 模式的残差代价判据：块残差全为 0 时只需发 skip 标志、
     * 无需传输残差，是编码代价最低的情形（H.264 8.4.1.3/8.4.1.4 的 P_Skip 语义）。
     * @param int $qp 量化参数（越界会被钳制到 0..51）
     * @return int 4x4 残差块 SAD 上限（≤ 该值时变换量化后 16 个系数全零）
     */
    public static function zeroResidualBlockSad(int $qp): int
    {
        // QP 缓存：同一 QP 的阈值只推导一次
        static $cache = [];
        // QP 合法范围 0..51
        $qp = max(0, min(51, $qp));
        if (isset($cache[$qp])) return $cache[$qp];
        // Inter 量化的乘数与偏移（与编码路径使用同一张表）
        $mf = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF[$qp];
        $ff = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF[$qp];
        // 各系数位置在 (FF+|coeff|)*MF ≤ 65535 时的最大 |coeff|
        $zeroCoeff = [];
        for ($j = 0; $j < 8; $j++) $zeroCoeff[$j] = intdiv(65535, $mf[$j]) - $ff[$j];
        // 4x4 整数变换基矢量的 l1 范数（行列方向）
        $norm = [1, 6, 4, 6];
        // 阈值初值取无穷，逐个系数位置取最小
        $limit = PHP_INT_MAX;
        for ($k = 0; $k < 4; $k++) {
            for ($l = 0; $l < 4; $l++) {
                // 4x4 系数位置 (k,l) 对应的量化乘数索引
                $j = ($k * 4 + $l) & 7;
                // 该位置对输入块 SAD 的 l1 放大上界
                $bound = $norm[$k] * $norm[$l];
                // 使该位置系数必为 0 的输入 SAD 上限
                $t = intdiv($zeroCoeff[$j], $bound);
                // 16 个位置取最小即为整块的安全阈值
                if ($t < $limit) $limit = $t;
            }
        }
        return $cache[$qp] = max(0, $limit);
    }

    /**
     * Tier2 阈值：16x16 宏块整数 (0,0) SAD 上限。
     * 低于该值时跳过昂贵的 6 抽头半/四像素插值精搜（整数 MV=(0,0) 的残差已落入
     * 量化死区量级，亚像素收益可忽略）。DCT/量化仍照常执行，仅影响压缩效率、不影响正确性。
     * 基础量级取 4 个 4x4 块 DC 系数的死区容量，倍数固定 4。
     * 从 SKIP 模式看：零 MV 且残差低于阈值时无需传 MV 与残差，是代价最小的编码路径。
     * @param int $qp 量化参数（越界会被钳制到 0..51）
     * @return int 16x16 宏块零 MV SAD 的亚像素跳过阈值
     */
    public static function subpelSkipSad(int $qp): int
    {
        // QP 缓存
        static $cache = [];
        // 钳制到合法 QP 范围
        $qp = max(0, min(51, $qp));
        if (isset($cache[$qp])) return $cache[$qp];
        // Inter 量化参数
        $mf = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF[$qp];
        $ff = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF[$qp];
        // DC 系数死区容量乘以固定倍数 4，作为 16x16 的保守阈值
        $mul = 4.0;
        // 返回按 QP 缓存的阈值
        return $cache[$qp] = (int)round(max(0, intdiv(65535, $mf[0]) - $ff[0]) * $mul);
    }

    /**
     * 运动估计：二级搜索（粗搜+精搜）备份实现
     * 速度较慢但搜索更充分，可用于质量敏感场景
     * @param array $currentBlock 当前块像素（$block[y][x]）
     * @param string $refPlane 参考帧亮度平面
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $searchRange 粗搜半径（整像素）
     * 与 motionEstimate16x16 的区别：先在整像素域以步长 4 做粗搜，
     * 再在粗搜最优点周围 ±3 做整像素精搜；不做亚像素细化。
     * 该实现用 ord() 逐字节读取参考帧、未使用 refInts 缓存，仅作质量对照。
     * @return array [mvX, mvY, sad] 运动向量和SAD值（mvX/mvY为1/4像素单位）
     */
    public function motionEstimate16x16TwoLevel(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        // 参考帧 stride/宽高（本实现直接读 refPlane 字节串）
        $refStride = $this->mbAlignedWidth;
        $refW = $this->mbAlignedWidth;
        $refH = $this->mbAlignedHeight;

        //$bestMV = [0, 0];
        //$bestSAD = PHP_INT_MAX;

        // 宏块左上角整像素坐标
        $origX = $mbX * 16;
        $origY = $mbY * 16;

        // 边缘宏块的有效宽高
        $blockW = min(16, $this->width - $origX);
        $blockH = min(16, $this->height - $origY);

        // 先检查(0,0)位置
        // (0,0) 位置的整块 SAD，对应零 MV 假设（P_Skip 语义见 8.4.1.3/8.4.1.4）
        $sad00 = 0;
        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                $refIdx = ($origY + $y) * $refStride + ($origX + $x);
                $sad00 += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
            }
        }
        // 当前最优：初始为零 MV
        $bestSAD = $sad00;
        $bestDX = 0;
        $bestDY = 0;

        // 大步长粗搜索（步长=4）
        $coarseStep = 4;
        for ($dy = -$searchRange; $dy <= $searchRange; $dy += $coarseStep) {
            for ($dx = -$searchRange; $dx <= $searchRange; $dx += $coarseStep) {
                // (0,0) 已单独计算，跳过
                if ($dx == 0 && $dy == 0) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                    continue;
                }

                // 逐样点 SAD（ord() 逐字节取像素）
                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        // 小步长精搜索（在粗搜索最佳点周围±3，步长=1）
        $refineRange = 3;
        for ($dy = $bestDY - $refineRange; $dy <= $bestDY + $refineRange; $dy++) {
            for ($dx = $bestDX - $refineRange; $dx <= $bestDX + $refineRange; $dx++) {
                // 与已算的 (0,0) 重复时跳过
                if ($dx == 0 && $dy == 0 && $bestDX == 0 && $bestDY == 0) continue;
                // 仍受 searchRange 限制（粗搜点可能已贴边）
                if (abs($dx) > $searchRange || abs($dy) > $searchRange) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        // 整像素 MV ×4 折算为 1/4 像素单位，与其他运动估计接口一致
        $bestMV = [$bestDX * 4, $bestDY * 4];
        // 返回 [mvX, mvY, sad]
        return [$bestMV[0], $bestMV[1], $bestSAD];
    }
}
