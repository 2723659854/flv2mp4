@echo off
echo ========================================
echo TS切片诊断
echo ========================================

echo.
echo [1] 基础信息
ffprobe -v error hls\a\b\segment_1.ts

echo.
echo [2] 视频流错误检查
ffprobe -v verbose hls\a\b\segment_1.ts 2>&1 | findstr /i "missing\|error\|warning\|no frame"

echo.
echo [3] 视频包大小分析（前10个）
ffprobe -v error -show_packets -select_streams v:0 hls\a\b\segment_1.ts 2>&1 | findstr "size=" | more

echo.
echo [4] 时间戳分析（前10个）
ffprobe -v error -show_packets -select_streams v:0 hls\a\b\segment_1.ts 2>&1 | findstr "pts_time=" | more

echo.
echo [5] 检查SPS/PPS数据包（小包可能是参数集）
echo 查找小于1000字节的视频包：
ffprobe -v error -show_entries packet=size,pts_time,flags -of csv hls\a\b\segment_1.ts | findstr /r "^[0-9]\{1,3\},"

pause