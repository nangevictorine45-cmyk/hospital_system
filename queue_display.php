<?php
/**
 * =============================================================================
 * CityCare Hospital - Public Waiting Room Live Queue Display
 * =============================================================================
 * 
 * Optimized for overhead lobby TV screens, monitor kiosks, and tablets.
 * Features:
 *  - High-contrast hospital dashboard
 *  - Real-time Web Audio API chime sound on newly called tickets
 *  - Live polling every 4 seconds via /api/queue_status.php
 *  - No login required; patient identity sanitized for privacy
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$baseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Queue Display - CityCare Hospital</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css">
    <style>
        body {
            background: #0f172a;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Display Header */
        .display-header {
            background: #1e293b;
            border-bottom: 2px solid #334155;
            padding: 18px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .display-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .display-logo-icon {
            background: #0284c7;
            color: #ffffff;
            font-size: 26px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
        }

        .display-title {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            margin: 0;
        }

        .display-subtitle {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .live-clock {
            font-family: monospace;
            font-size: 28px;
            font-weight: 800;
            color: #38bdf8;
            background: #0f172a;
            padding: 8px 18px;
            border-radius: 8px;
            border: 1px solid #334155;
            letter-spacing: 1px;
        }

        /* Room Grid */
        .display-container {
            flex: 1;
            padding: 28px 32px;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
        }

        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 24px;
        }

        .room-card {
            background: #1e293b;
            border: 2px solid #334155;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .room-card.calling-pulse {
            border-color: #38bdf8;
            box-shadow: 0 0 35px rgba(56, 189, 248, 0.45);
            animation: pulseGlow 2s infinite;
        }

        @keyframes pulseGlow {
            0% { transform: scale(1); box-shadow: 0 0 25px rgba(56, 189, 248, 0.3); }
            50% { transform: scale(1.015); box-shadow: 0 0 45px rgba(56, 189, 248, 0.6); }
            100% { transform: scale(1); box-shadow: 0 0 25px rgba(56, 189, 248, 0.3); }
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #334155;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }

        .room-badge {
            background: #0284c7;
            color: #ffffff;
            font-size: 16px;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }

        .doctor-name {
            font-size: 18px;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
        }

        .doctor-specialty {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* Now Serving Box */
        .serving-box {
            background: #0f172a;
            border: 2px solid #334155;
            border-radius: 14px;
            padding: 20px;
            text-align: center;
            margin-bottom: 18px;
        }

        .serving-label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #38bdf8;
            margin-bottom: 4px;
        }

        .serving-ticket {
            font-size: 64px;
            font-weight: 900;
            letter-spacing: 3px;
            color: #38bdf8;
            line-height: 1;
            margin: 8px 0;
            text-shadow: 0 0 20px rgba(56, 189, 248, 0.4);
        }

        .serving-patient {
            font-size: 15px;
            color: #cbd5e1;
            font-weight: 600;
        }

        /* Next In Line Box */
        .next-box {
            margin-top: auto;
            border-top: 1px solid #334155;
            padding-top: 14px;
        }

        .next-label {
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .next-tickets-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .next-ticket-pill {
            background: #334155;
            color: #f8fafc;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 1px solid #475569;
        }

        /* Bottom Status Banner */
        .display-footer {
            background: #1e293b;
            border-top: 1px solid #334155;
            padding: 12px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #94a3b8;
        }

        .sound-toggle-btn {
            background: #334155;
            border: 1px solid #475569;
            color: #f8fafc;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .sound-toggle-btn:hover {
            background: #475569;
        }
    </style>
</head>
<body>

    <!-- Top Display Header -->
    <header class="display-header">
        <div class="display-brand">
            <div class="display-logo-icon">&#10010;</div>
            <div>
                <h1 class="display-title">CityCare Hospital</h1>
                <div class="display-subtitle">Main Outpatient Waiting Lobby &bull; Real-Time Consultation Queue</div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 16px;">
            <button type="button" class="sound-toggle-btn" id="soundToggleBtn" onclick="toggleAudioChime()">
                <span id="soundIcon">&#128266;</span>
                <span id="soundText">Chime: ON</span>
            </button>
            <div class="live-clock" id="digitalClock">--:--:--</div>
        </div>
    </header>

    <!-- Main Live Rooms Grid -->
    <main class="display-container">
        <div class="rooms-grid" id="roomsContainer">
            <!-- Dynamically populated via AJAX polling -->
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #94a3b8;">
                <div style="font-size: 32px; margin-bottom: 12px;">&#8987;</div>
                <div style="font-size: 18px; font-weight: 600;">Connecting to CityCare Hospital Queue Engine...</div>
            </div>
        </div>
    </main>

    <!-- Bottom Footer -->
    <footer class="display-footer">
        <div>
            &#128161; Please hold your ticket slip. When your number appears, proceed promptly to the indicated consultation room.
        </div>
        <div style="display: flex; align-items: center; gap: 14px;">
            <span id="feedStatus" style="color: #4ade80;">● Live Connected</span>
            <span style="opacity: 0.6;">Auto-refreshes every 4s</span>
        </div>
    </footer>

    <!-- Live Polling & Audio Chime Script -->
    <script>
        let audioEnabled = true;
        let lastKnownServingTickets = {};
        const apiUrl = '<?php echo $baseUrl; ?>/api/queue_status.php';

        // 1. Digital Clock
        function updateClock() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('digitalClock').textContent = `${hours}:${minutes}:${seconds}`;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 2. Web Audio API Chime (Realistic 2-tone hospital ding-dong)
        function playChimeSound() {
            if (!audioEnabled) return;
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                const ctx = new AudioContext();

                // Tone 1: High tone (800 Hz)
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(784, ctx.currentTime); // G5
                gain1.gain.setValueAtTime(0.3, ctx.currentTime);
                gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.start(ctx.currentTime);
                osc1.stop(ctx.currentTime + 0.6);

                // Tone 2: Harmonious tone (523 Hz)
                const osc2 = ctx.createOscillator();
                const gain2 = ctx.createGain();
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(523.25, ctx.currentTime + 0.25); // C5
                gain2.gain.setValueAtTime(0.35, ctx.currentTime + 0.25);
                gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 1.2);
                osc2.connect(gain2);
                gain2.connect(ctx.destination);
                osc2.start(ctx.currentTime + 0.25);
                osc2.stop(ctx.currentTime + 1.2);
            } catch (e) {
                console.log('Audio chime error:', e);
            }
        }

        function toggleAudioChime() {
            audioEnabled = !audioEnabled;
            const icon = document.getElementById('soundIcon');
            const text = document.getElementById('soundText');
            if (audioEnabled) {
                icon.textContent = '🔊';
                text.textContent = 'Chime: ON';
                playChimeSound(); // Play test chime
            } else {
                icon.textContent = '🔇';
                text.textContent = 'Chime: OFF';
            }
        }

        // 3. Polling Queue State
        async function fetchQueueState() {
            try {
                const response = await fetch(apiUrl);
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();

                if (!data.success || !Array.isArray(data.rooms)) {
                    throw new Error('Invalid feed payload');
                }

                renderRooms(data.rooms);
                document.getElementById('feedStatus').textContent = '● Live Connected';
                document.getElementById('feedStatus').style.color = '#4ade80';
            } catch (err) {
                console.warn('Queue feed polling error:', err);
                document.getElementById('feedStatus').textContent = '⚠️ Reconnecting...';
                document.getElementById('feedStatus').style.color = '#f59e0b';
            }
        }

        function renderRooms(rooms) {
            const container = document.getElementById('roomsContainer');
            let shouldPlayChime = false;

            let html = '';
            rooms.forEach(room => {
                const serving = room.now_serving;
                const currentTicket = serving ? serving.queue_number : null;

                // Check if this room has called a brand new ticket
                if (currentTicket && lastKnownServingTickets[room.doctor_id] !== currentTicket) {
                    // Only play if not initial load
                    if (Object.keys(lastKnownServingTickets).length > 0) {
                        shouldPlayChime = true;
                    }
                    lastKnownServingTickets[room.doctor_id] = currentTicket;
                }

                const isPulse = serving && serving.minutes_in_service <= 2;

                html += `
                    <div class="room-card ${isPulse ? 'calling-pulse' : ''}">
                        <div class="room-header">
                            <div>
                                <h2 class="doctor-name">${escapeHtml(room.doctor_name)}</h2>
                                <div class="doctor-specialty">${escapeHtml(room.specialty)}</div>
                            </div>
                            <div class="room-badge">${escapeHtml(room.room_number)}</div>
                        </div>

                        <div class="serving-box">
                            <div class="serving-label">Now Serving</div>
                            ${serving ? `
                                <div class="serving-ticket">${escapeHtml(serving.queue_number)}</div>
                                <div class="serving-patient">${escapeHtml(serving.patient_display || 'In consultation')}</div>
                            ` : `
                                <div class="serving-ticket" style="font-size: 38px; color: #64748b; letter-spacing: 1px; margin: 20px 0;">IDLE</div>
                                <div class="serving-patient" style="color: #64748b;">No active consultation</div>
                            `}
                        </div>

                        <div class="next-box">
                            <div class="next-label">Next in Line (${room.waiting_count})</div>
                            <div class="next-tickets-row">
                                ${room.waiting_list.length > 0 ? (
                                    room.waiting_list.slice(0, 4).map(w => `
                                        <div class="next-ticket-pill">${escapeHtml(w.queue_number)}</div>
                                    `).join('')
                                ) : `
                                    <span style="font-size: 13px; color: #64748b;">Waiting room clear</span>
                                `}
                            </div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;

            if (shouldPlayChime) {
                playChimeSound();
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Start Polling every 4 seconds
        fetchQueueState();
        setInterval(fetchQueueState, 4000);
    </script>
</body>
</html>

