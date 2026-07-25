<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

trait SpsPpsTrait
{
    public function generateSPS(): string
    {
        $profileIdc = 66;
        $levelIdc = 10;

        $picWidthInMbs = (int)ceil($this->width / 16);
        $picHeightInMapUnits = (int)ceil($this->height / 16);

        $bits = '';

        $bits .= $this->u($profileIdc, 8);

        $bits .= '1';
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0000';
        $bits .= $this->u($levelIdc, 8);

        $bits .= $this->ue(0);

        $this->log2MaxFrameNumMinus4 = 3;
        $bits .= $this->ue($this->log2MaxFrameNumMinus4);

        $picOrderCntType = 0;
        $bits .= $this->ue($picOrderCntType);

        $this->log2MaxPicOrderCntLsbMinus4 = 0;
        $bits .= $this->ue($this->log2MaxPicOrderCntLsbMinus4);

        $numRefFrames = 1;
        $bits .= $this->ue($numRefFrames);

        $gapsInFrameNumValueAllowedFlag = false;
        $bits .= $gapsInFrameNumValueAllowedFlag ? '1' : '0';

        $bits .= $this->ue($picWidthInMbs - 1);
        $bits .= $this->ue($picHeightInMapUnits - 1);

        $bits .= '1';

        $bits .= '0';

        $cropLeft = 0;
        $cropRight = ($picWidthInMbs * 16 - $this->width);
        $cropTop = 0;
        $cropBottom = ($picHeightInMapUnits * 16 - $this->height);
        $frameCroppingFlag = ($cropLeft > 0 || $cropRight > 0 || $cropTop > 0 || $cropBottom > 0);
        $bits .= $frameCroppingFlag ? '1' : '0';
        if ($frameCroppingFlag) {
            $bits .= $this->ue((int)($cropLeft / 2));
            $bits .= $this->ue((int)($cropRight / 2));
            $bits .= $this->ue((int)($cropTop / 2));
            $bits .= $this->ue((int)($cropBottom / 2));
        }

        $bits .= '1';

        $bits .= $this->vuiParameters();

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    public function vuiParameters(): string
    {
        $bits = '';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '1';

        $bits .= '1';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(16);
        $bits .= $this->ue(16);
        $bits .= $this->ue(0);
        $bits .= $this->ue(1);
        return $bits;
    }

    public function generatePPS()
    {
        $bits = '';

        $bits .= $this->ue(0);              // pic_parameter_set_id = 0
        $bits .= $this->ue(0);              // seq_parameter_set_id = 0
        $bits .= '0';                       // entropy_coding_mode_flag = 0 (CAVLC)
        $bits .= '0';                       // bottom_field_pic_order_in_frame_present_flag = 0
        $bits .= $this->ue(0);              // num_slice_groups_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l0_default_active_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l1_default_active_minus1 = 0
        $bits .= '0';                       // weighted_pred_flag = 0
        $bits .= '00';                      // weighted_bipred_idc = 0
        $bits .= $this->se($this->qp - 26); // pic_init_qp_minus26
        $bits .= $this->se(0);              // pic_init_qs_minus26
        $bits .= $this->se($this->chromaQpIndexOffset); // chroma_qp_index_offset
        $bits .= '1';                       // deblocking_filter_control_present_flag = 1
        $bits .= '0';                       // constrained_intra_pred_flag = 0
        $bits .= '0';                       // redundant_pic_cnt_present_flag = 0

        $bits .= '1';                       // rbsp_stop_one_bit
        while (strlen($bits) % 8 != 0) $bits .= '0';
        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }
}
