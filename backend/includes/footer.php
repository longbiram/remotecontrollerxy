    </main>
</div>

<!-- Video Stream Preview Modal -->
<div class="modal-overlay" id="videoPlayerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="card-title" id="modalVideoTitle">Stream Preview</h3>
            <button type="button" class="btn-icon" id="closeVideoModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="video-container">
                <video id="modalVideoPlayer" controls playsinline></video>
            </div>
            <div style="margin-top: 1rem; font-size: 0.8rem; color: var(--text-dim); display: flex; justify-content: space-between;">
                <span>Supports MP4 direct playback & HLS .m3u8 live streams</span>
                <span id="playerCodecBadge">Native / HLS.js</span>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
