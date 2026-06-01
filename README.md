## FLV to MP4 Converter Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

### Introduction

This is a pure PHP tool for converting FLV media files to MP4 format, making it easier to store and process them for further use.

### Installation

```bash
composer require xiaosongshu/flv2mp4
```

### Usage Examples

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// Path to the FLV media file to convert
$file = __DIR__."/test.flv";

// Example 1: Convert to a single MP4 file for recorded playback.
// Also generates segments for live streaming. The plugin automatically clears the output directory and regenerates segments.
echo "=== Example 1: Converting to a single MP4 file ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "\nConversion completed: " . $res . "\n\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Example 2: Generate separate audio and video segments (for browser playback, primarily for live streaming).
// The plugin automatically clears the output directory and regenerates segments.
echo "=== Example 2: Generating separate audio/video segments ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "\nConversion completed! Generated files:\n";
    echo "  Audio Initialization: " . ($res['audioInit'] ?? 'None') . "\n";
    echo "  Video Initialization: " . ($res['videoInit'] ?? 'None') . "\n";
    echo "  Number of Audio Segments: " . count($res['audioSegments']) . "\n";
    echo "  Number of Video Segments: " . count($res['videoSegments']) . "\n";
    echo "  Metadata File: " . ($res['meta'] ?? 'None') . "\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n";
}
```

The merged MP4 file can be played directly in a browser using the standard `<video>` tag. Example code:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Play Video</title>
</head>
<body>
<video id="video" controls width="800">
    <!-- Replace with the actual file path -->
    <source src="output_merge/demo.mp4" type="video/mp4">
    Your browser does not support the video tag.
</video>
</body>
</html>
```

This plugin also generates fMP4 segments suitable for live streaming. The following example shows how to play both types of segmented outputs. Choose the method that fits your needs.

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>fMP4 Player - Separate Audio/Video Mode</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 20px; 
            background: #1a1a1a; 
            color: #fff; 
        }
        video { 
            width: 100%; 
            background: #000; 
            border-radius: 12px; 
            max-height: 600px;
        }
        .controls {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            background: #4CAF50;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover { background: #45a049; }
        .btn:disabled { background: #555; cursor: not-allowed; }
        .btn-danger { background: #f44336; }
        .btn-danger:hover { background: #da190b; }
        .btn-secondary { background: #607D8B; }
        .btn-secondary:hover { background: #546E7A; }
        #status { 
            margin-top: 15px; 
            padding: 15px; 
            background: rgba(255,255,255,0.05); 
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .status-info { color: #4CAF50; }
        .status-warning { color: #ff9800; }
        .status-error { color: #f44336; }
        .status-debug { color: #2196F3; }
        .mode-selector {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
        }
        .mode-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .mode-btn.active {
            background: #4CAF50;
            border-color: #4CAF50;
        }
        .mode-btn:hover:not(.active) {
            background: rgba(255,255,255,0.15);
        }
        .info-panel {
            margin-top: 15px;
            padding: 15px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            font-size: 14px;
        }
        .info-panel h3 {
            margin: 0 0 10px 0;
            color: #4CAF50;
        }
        .info-panel ul {
            margin: 0;
            padding-left: 20px;
        }
        .info-panel li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <h1>fMP4 Stream Player</h1>
    
    <!-- Mode Selection -->
    <div class="mode-selector">
        <button class="mode-btn active" data-mode="separate">Separate Audio/Video Mode</button>
        <button class="mode-btn" data-mode="merged">Merged Mode</button>
    </div>

    <!-- Information Panel -->
    <div class="info-panel">
        <h3>Current Mode: <span id="modeInfo">Separate Audio/Video Mode</span></h3>
        <ul>
            <li><strong>Separate Mode</strong>: Uses audio_init.mp4 + video_init.mp4 + separate .m4s segments</li>
            <li><strong>Merged Mode</strong>: Uses init.mp4 + merged .m4s segments</li>
            <li>Output Directory: <code>/output_separate/</code> (Separate Mode) or <code>/output_merge/</code> (Merged Mode)</li>
        </ul>
    </div>

    <video id="video" controls autoplay muted playsinline></video>
    
    <div class="controls">
        <button id="playBtn" class="btn">Play</button>
        <button id="pauseBtn" class="btn" disabled>Pause</button>
        <button id="resetBtn" class="btn btn-danger">Reset</button>
        <button id="debugBtn" class="btn btn-secondary">Show Debug Info</button>
    </div>
    
    <div id="status">Ready to play...</div>

<script>
const video = document.getElementById('video');
const statusDiv = document.getElementById('status');
const modeInfo = document.getElementById('modeInfo');

// Current playback mode
let currentMode = 'separate';
let BASE_URL = '/output_separate/';

let mediaSource;
let audioSourceBuffer, videoSourceBuffer;
let streamEnded = false;
let segmentIdx = 1;
let isDebugMode = false;
let metaInfo = null;

// Add status log
function addStatus(message, type = 'info') {
    const timestamp = new Date().toLocaleTimeString();
    const typeClass = `status-${type}`;
    statusDiv.innerHTML += `<div class="${typeClass}">[${timestamp}] ${message}</div>`;
    statusDiv.scrollTop = statusDiv.scrollHeight;
    console.log(`[fMP4] ${message}`);
}

// Append data to SourceBuffer
async function appendBuffer(sourceBuffer, data, description) {
    return new Promise((resolve, reject) => {
        const onUpdateEnd = () => {
            sourceBuffer.removeEventListener('error', onError);
            addStatus(`${description} appended successfully (${data.byteLength} bytes)`, 'debug');
            resolve();
        };
        
        const onError = (e) => {
            sourceBuffer.removeEventListener('updateend', onUpdateEnd);
            addStatus(`SourceBuffer error: ${e.type}`, 'error');
            reject(new Error('SourceBuffer error'));
        };

        const waitAndAppend = () => {
            if (sourceBuffer.updating) {
                setTimeout(waitAndAppend, 50);
                return;
            }
            
            try {
                sourceBuffer.addEventListener('updateend', onUpdateEnd, { once: true });
                sourceBuffer.addEventListener('error', onError, { once: true });
                sourceBuffer.appendBuffer(data);
                addStatus(`Appending ${description}...`, 'debug');
            } catch (e) {
                addStatus(`appendBuffer error: ${e.message}`, 'error');
                reject(e);
            }
        };
        
        waitAndAppend();
    });
}

// Get codec information
async function getCodecs() {
    try {
        const res = await fetch(BASE_URL + 'meta.json');
        if (!res.ok) {
            addStatus(`Failed to load meta.json: HTTP ${res.status}`, 'warning');
            return { videoCodec: 'avc1.42E01E', audioCodec: 'mp4a.40.2' };
        }
        metaInfo = await res.json();
        addStatus(`Codecs: Video=${metaInfo.videoCodec}, Audio=${metaInfo.audioCodec}`, 'debug');
        return {
            videoCodec: metaInfo.videoCodec || 'avc1.42E01E',
            audioCodec: metaInfo.audioCodec || 'mp4a.40.2'
        };
    } catch (e) {
        addStatus(`Failed to get codec info: ${e.message}`, 'warning');
        return { videoCodec: 'avc1.42E01E', audioCodec: 'mp4a.40.2' };
    }
}

// Load initialization segments (Separate Mode)
async function loadInitSeparate(codecs) {
    addStatus('Loading initialization segments...', 'info');
    
    try {
        const audioRes = await fetch(BASE_URL + 'audio_init.mp4');
        if (!audioRes.ok) throw new Error(`Failed to load audio initialization: HTTP ${audioRes.status}`);
        const audioInit = await audioRes.arrayBuffer();
        addStatus(`Audio initialization data: ${audioInit.byteLength} bytes`, 'debug');
        
        const videoRes = await fetch(BASE_URL + 'video_init.mp4');
        if (!videoRes.ok) throw new Error(`Failed to load video initialization: HTTP ${videoRes.status}`);
        const videoInit = await videoRes.arrayBuffer();
        addStatus(`Video initialization data: ${videoInit.byteLength} bytes`, 'debug');
        
        audioSourceBuffer = mediaSource.addSourceBuffer(`audio/mp4; codecs="${codecs.audioCodec.toLowerCase()}"`);
        audioSourceBuffer.mode = 'sequence';
        
        videoSourceBuffer = mediaSource.addSourceBuffer(`video/mp4; codecs="${codecs.videoCodec.toLowerCase()}"`);
        videoSourceBuffer.mode = 'sequence';
        
        addStatus('SourceBuffers created successfully', 'info');
        
        await appendBuffer(audioSourceBuffer, audioInit, 'Audio Initialization');
        await appendBuffer(videoSourceBuffer, videoInit, 'Video Initialization');
        
        addStatus('Initialization complete', 'info');
        
    } catch (e) {
        addStatus(`Failed to load initialization segments: ${e.message}`, 'error');
        throw e;
    }
}

// Load initialization segments (Merged Mode)
async function loadInitMerged(codecs) {
    addStatus('Loading initialization segments...', 'info');
    
    try {
        const res = await fetch(BASE_URL + 'init.mp4');
        if (!res.ok) throw new Error(`Failed to load initialization file: HTTP ${res.status}`);
        
        const initData = await res.arrayBuffer();
        addStatus(`Initialization data: ${initData.byteLength} bytes`, 'debug');
        
        const codecStr = `${codecs.videoCodec.toLowerCase()},${codecs.audioCodec.toLowerCase()}`;
        audioSourceBuffer = mediaSource.addSourceBuffer(`video/mp4; codecs="${codecStr}"`);
        audioSourceBuffer.mode = 'sequence';
        videoSourceBuffer = null;
        
        addStatus(`SourceBuffer created: ${codecStr}`, 'info');
        
        await appendBuffer(audioSourceBuffer, initData, 'Initialization data');
        addStatus('Initialization complete', 'info');
        
    } catch (e) {
        addStatus(`Failed to load initialization segments: ${e.message}`, 'error');
        throw e;
    }
}

// Load media segments (Separate Mode)
async function loadSegmentsSeparate() {
    addStatus('Starting to load media segments...', 'info');
    
    while (!streamEnded && mediaSource.readyState === 'open') {
        try {
            const audioResp = await fetch(`${BASE_URL}audio_${segmentIdx}.m4s`);
            if (audioResp.status === 404) {
                addStatus(`Audio segment ${segmentIdx} not found`, 'warning');
            }
            
            const videoResp = await fetch(`${BASE_URL}video_${segmentIdx}.m4s`);
            if (videoResp.status === 404) {
                addStatus(`Video segment ${segmentIdx} not found, stream may have ended`, 'warning');
                streamEnded = true;
                break;
            }
            
            if (!videoResp.ok) throw new Error(`HTTP ${videoResp.status}`);
            
            const [audioData, videoData] = await Promise.all([
                audioResp.status === 200 ? audioResp.arrayBuffer() : null,
                videoResp.arrayBuffer()
            ]);
            
            if (audioData) {
                await appendBuffer(audioSourceBuffer, audioData, `Audio segment ${segmentIdx}`);
            }
            await appendBuffer(videoSourceBuffer, videoData, `Video segment ${segmentIdx}`);
            
            segmentIdx++;
            
        } catch (e) {
            addStatus(`Failed to load segment: ${e.message}`, 'error');
            streamEnded = true;
            break;
        }
    }
    
    if (mediaSource.readyState === 'open') {
        try {
            mediaSource.endOfStream();
            addStatus('Stream playback ended', 'info');
        } catch (e) {
            addStatus(`endOfStream failed: ${e.message}`, 'warning');
        }
    }
}

// Load media segments (Merged Mode)
async function loadSegmentsMerged() {
    addStatus('Starting to load media segments...', 'info');
    
    while (!streamEnded && mediaSource.readyState === 'open') {
        try {
            const resp = await fetch(`${BASE_URL}segment_${segmentIdx}.m4s`);
            
            if (resp.status === 404) {
                addStatus(`Segment ${segmentIdx} not found, stream may have ended`, 'warning');
                streamEnded = true;
                break;
            }
            
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            
            const data = await resp.arrayBuffer();
            await appendBuffer(audioSourceBuffer, data, `Segment ${segmentIdx}`);
            segmentIdx++;
            
        } catch (e) {
            addStatus(`Failed to load segment: ${e.message}`, 'error');
            streamEnded = true;
            break;
        }
    }
    
    if (mediaSource.readyState === 'open') {
        try {
            mediaSource.endOfStream();
            addStatus('Stream playback ended', 'info');
        } catch (e) {
            addStatus(`endOfStream failed: ${e.message}`, 'warning');
        }
    }
}

// Initialize MediaSource
async function initMediaSource() {
    if (mediaSource) {
        try {
            if (mediaSource.readyState === 'open') {
                mediaSource.endOfStream();
            }
        } catch (e) {}
        URL.revokeObjectURL(video.src);
    }
    
    streamEnded = false;
    segmentIdx = 1;
    audioSourceBuffer = null;
    videoSourceBuffer = null;
    
    document.getElementById('playBtn').disabled = true;
    document.getElementById('pauseBtn').disabled = true;
    statusDiv.innerHTML = `<div class="status-info">[Init] Preparing player... (Mode: ${currentMode === 'separate' ? 'Separate Audio/Video' : 'Merged'})</div>`;
    
    try {
        mediaSource = new MediaSource();
        video.src = URL.createObjectURL(mediaSource);
        
        mediaSource.addEventListener('sourceopen', async () => {
            try {
                addStatus('MediaSource opened', 'debug');
                
                const codecs = await getCodecs();
                
                if (currentMode === 'separate') {
                    await loadInitSeparate(codecs);
                    await loadSegmentsSeparate();
                } else {
                    await loadInitMerged(codecs);
                    await loadSegmentsMerged();
                }
                
                document.getElementById('playBtn').disabled = false;
                document.getElementById('pauseBtn').disabled = false;
                
            } catch (e) {
                addStatus(`Initialization failed: ${e.message}`, 'error');
                console.error('MediaSource initialization error:', e);
            }
        });
        
        mediaSource.addEventListener('sourceclose', () => {
            addStatus('MediaSource closed', 'debug');
        });
        
        mediaSource.addEventListener('error', (e) => {
            addStatus(`MediaSource error: ${e}`, 'error');
        });
        
    } catch (e) {
        addStatus(`Failed to create MediaSource: ${e.message}`, 'error');
    }
}

// Mode switching
document.querySelectorAll('.mode-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        currentMode = btn.dataset.mode;
        BASE_URL = currentMode === 'separate' ? '/output_separate/' : '/output_merge/';
        
        modeInfo.textContent = currentMode === 'separate' ? 'Separate Audio/Video Mode' : 'Merged Mode';
        addStatus(`Switched to ${currentMode === 'separate' ? 'Separate Audio/Video' : 'Merged'} mode`, 'info');
        
        initMediaSource();
    });
});

// Event listeners
document.getElementById('playBtn').addEventListener('click', () => {
    video.play();
    document.getElementById('playBtn').disabled = true;
    document.getElementById('pauseBtn').disabled = false;
});

document.getElementById('pauseBtn').addEventListener('click', () => {
    video.pause();
    document.getElementById('pauseBtn').disabled = true;
    document.getElementById('playBtn').disabled = false;
});

document.getElementById('resetBtn').addEventListener('click', () => {
    streamEnded = true;
    initMediaSource();
});

document.getElementById('debugBtn').addEventListener('click', () => {
    isDebugMode = !isDebugMode;
    statusDiv.style.display = isDebugMode ? 'block' : 'none';
    document.getElementById('debugBtn').textContent = isDebugMode ? 'Hide Debug Info' : 'Show Debug Info';
});

video.addEventListener('error', (e) => {
    addStatus(`Video element error: ${video.error?.message || e}`, 'error');
});

video.addEventListener('playing', () => {
    addStatus('Video started playing', 'info');
});

video.addEventListener('pause', () => {
    addStatus('Video paused', 'info');
});

video.addEventListener('ended', () => {
    addStatus('Video playback ended', 'info');
});

// Start the player
initMediaSource();
</script>
</body>
</html>
```

### Background

This project was originally developed to provide MP4 storage support for another live streaming project, [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).

### Disclaimer

-   Some code or materials in this project may originate from online sources. If any copyright infringement occurs, please contact the author to have it removed.
-   This project is completely open-source and intended solely for technology sharing and learning exchange.
-   The author is not responsible for any legal risks or commercial disputes arising from the user's own actions.
-   Users should bear the consequences of using this project, including but not limited to copyright and compliance issues.

### Contact the Author

-   Email: 2723659854@qq.com