<?php
add_shortcode('hudobne-albumy', static function ($atts = array(), $content = null) {
    $base_url = 'https://mamateyka.sk/wp-content/uploads/2026/10/';

    $albums = array(
        array(
            'title' => 'Príď ku mne',
            'year' => '2019',
            'tracks' => array(
                array('number' => '01', 'title' => 'Keď vstúpi Boh', 'file' => '01. Keď vstúpi Boh.mp3', 'duration' => 230),
                array('number' => '02', 'title' => 'Nežne zlomený', 'file' => '02. Nežne zlomený.mp3', 'duration' => 276),
                array('number' => '03', 'title' => 'Vyvýšený', 'file' => '03. Vyvýšený.mp3', 'duration' => 334),
                array('number' => '04', 'title' => 'Takto sa modlím', 'file' => '04. Takto sa modlím.mp3', 'duration' => 294),
                array('number' => '05', 'title' => 'Svätý, Svätý', 'file' => '05. Svätý, Svätý.mp3', 'duration' => 219),
                array('number' => '06', 'title' => 'Dôveruj', 'file' => '06. Dôveruj.mp3', 'duration' => 264),
                array('number' => '07', 'title' => 'Skláňaš sa k nám', 'file' => '07. Skláňas sa k nám.mp3', 'duration' => 251),
                array('number' => '08', 'title' => 'Nekonečný Boh', 'file' => '08. Nekonečný Boh.mp3', 'duration' => 328),
                array('number' => '09', 'title' => 'Láska prišla dole k nám', 'file' => '09. Láska prišla dole k nám.mp3', 'duration' => 275),
                array('number' => '10', 'title' => 'Príď ku mne', 'file' => '10. Príď ku mne.mp3', 'duration' => 229),
                array('number' => '11', 'title' => 'Padám tisíckrát', 'file' => '11. Padám tisíckrát.mp3', 'duration' => 296),
                array('number' => '12', 'title' => 'Boh víťazí', 'file' => '12. Boh víťazí.mp3', 'duration' => 352),
                array('number' => '13', 'title' => 'Slnko a Mesiac', 'file' => '13. Slnko a Mesiac.mp3', 'duration' => 299),
                array('number' => '14', 'title' => 'Bližšie', 'file' => '14. Bližšie.mp3', 'duration' => 261),
            ),
        ),
        array(
            'title' => 'Nádej nezahanbuje',
            'year' => '2025',
            'tracks' => array(
                array('number' => '1', 'title' => 'Ja verím', 'file' => '1. Ja verím.mp3', 'duration' => 217),
                array('number' => '2', 'title' => 'Všetko vezmi si', 'file' => '2. Všetko vezmi si.mp3', 'duration' => 249),
                array('number' => '3', 'title' => 'Všetko pochopíš', 'file' => '3. Všetko pochopíš.mp3', 'duration' => 252),
                array('number' => '4', 'title' => 'Už je čas', 'file' => '4. Už je čas.mp3', 'duration' => 192),
                array('number' => '5', 'title' => 'Poď za mnou', 'file' => '5. Poď za mnou.mp3', 'duration' => 241),
                array('number' => '6', 'title' => 'Chcem len Teba', 'file' => '6. Chcem len Teba.mp3', 'duration' => 204),
                array('number' => '7', 'title' => 'Duchu Svätý, si tu vítaný', 'file' => '7. Duchu Svätý, si tu vítaný.mp3', 'duration' => 328),
                array('number' => '8', 'title' => 'Zo skaly kvapká med', 'file' => '8. Zo skaly kvapká med.mp3', 'duration' => 250),
                array('number' => '9', 'title' => 'Tancujme', 'file' => '9. Tancujme.mp3', 'duration' => 254),
                array('number' => '10', 'title' => 'Potrebujem Ťa', 'file' => '10. Potrebujem Ťa.mp3', 'duration' => 285),
                array('number' => '11', 'title' => 'Ja sa postarám', 'file' => '11. Ja sa postarám.mp3', 'duration' => 295),
                array('number' => '12', 'title' => 'Nikto, len ty', 'file' => '12. Nikto, len ty.mp3', 'duration' => 252),
                array('number' => '13', 'title' => 'Veď ma sám', 'file' => '13. Veď ma sám.mp3', 'duration' => 296),
            ),
        ),
        array(
            'title' => 'Vianoce Live',
            'year' => '2022',
            'tracks' => array(
                array('number' => '1', 'title' => 'Folkové Vianoce', 'file' => 'folkove-vianoce.mp3', 'duration' => 178),
                array('number' => '2', 'title' => 'Hosana', 'file' => 'hosana.mp3', 'duration' => 182),
                array('number' => '3', 'title' => 'Idú traja králi', 'file' => 'idu-traja-krali.mp3', 'duration' => 112),
                array('number' => '4', 'title' => 'Pašli pastieri', 'file' => 'pasli-pastieri.mp3', 'duration' => 66),
                array('number' => '5', 'title' => 'Poďme bratia do Betlema', 'file' => 'podme-bratia-do-betlema.mp3', 'duration' => 144),
                array('number' => '6', 'title' => 'Pokoj vám', 'file' => 'pokoj-vam.mp3', 'duration' => 180),
                array('number' => '7', 'title' => 'Prišla radosť', 'file' => 'prisla-radost.mp3', 'duration' => 116),
                array('number' => '8', 'title' => 'Sviečok čas', 'file' => 'sviecok-cas.mp3', 'duration' => 168),
                array('number' => '9', 'title' => 'Vianočný čas', 'file' => 'vianocny-cas.mp3', 'duration' => 166),
            ),
        ),
    );

    usort($albums, static function ($left, $right) {
        return (int) $left['year'] <=> (int) $right['year'];
    });

    static $assets_printed = false;

    ob_start();
    if (!$assets_printed) {
        $assets_printed = true;
        ?>
        <style>
            .ha-albums {
                --ha-ink: #1d2630;
                --ha-muted: #64717c;
                --ha-blue: #537891;
                --ha-blue-dark: #3f6174;
                --ha-border: #d8e3e9;
                --ha-surface: #fff;
                --ha-soft: #edf4f7;
                width: 100%;
                max-width: none;
                margin: 2rem 0;
                padding: 0;
                color: var(--ha-ink);
                font-family: "Fira Sans", sans-serif;
            }
            .ha-albums *,
            .ha-albums *::before,
            .ha-albums *::after {
                box-sizing: border-box;
            }
            .ha-albums__intro {
                margin: 0 0 1.2rem;
            }
            .ha-albums__intro h2 {
                margin: 0 0 .4rem;
                color: var(--ha-ink);
                font: inherit;
                font-size: clamp(1.7rem, 4vw, 2.25rem);
                font-weight: 700;
                line-height: 1.2;
            }
            .ha-albums__intro p {
                margin: 0;
                color: var(--ha-muted);
            }
            .ha-player {
                position: fixed;
                bottom: max(1rem, env(safe-area-inset-bottom));
                left: 50%;
                z-index: 999;
                display: grid;
                width: min(1080px, calc(100vw - 2rem));
                grid-template-columns: minmax(190px, .8fr) minmax(0, 1.2fr);
                align-items: center;
                gap: .85rem 1.25rem;
                margin: 0;
                padding: 1rem 1.1rem;
                border: 1px solid var(--ha-border);
                border-left: 4px solid var(--ha-blue);
                border-radius: 16px;
                background: var(--ha-surface);
                box-shadow: 0 5px 18px rgba(29, 38, 48, .06);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transform: translate(-50%, 1rem);
                transition: opacity .25s ease, transform .25s ease, visibility 0s linear .25s;
            }
            .ha-player.has-track {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
                transform: translate(-50%, 0);
                transition: opacity .25s ease, transform .25s ease, visibility 0s;
            }
            .ha-player__identity {
                display: flex;
                min-width: 0;
                align-items: center;
                gap: .8rem;
            }
            .ha-player__close {
                position: absolute;
                top: .5rem;
                right: .5rem;
                z-index: 1;
                display: grid;
                width: 40px;
                height: 40px;
                flex: 0 0 auto;
                place-items: center;
                border: 1px solid var(--ha-border);
                border-radius: 50%;
                background: var(--ha-soft);
                color: var(--ha-blue-dark);
                cursor: pointer;
                transition: background-color .15s ease, color .15s ease;
            }
            .ha-player__close:hover {
                background: var(--ha-blue);
                color: #fff;
            }
            .ha-player__close .ha-icon {
                width: 18px;
                height: 18px;
            }
            .ha-icon {
                display: block;
                width: 20px;
                height: 20px;
                flex: 0 0 auto;
            }
            .ha-albums .ha-icon[hidden] {
                display: none !important;
            }
            .ha-player__icon {
                display: grid;
                width: 3.25rem;
                height: 3.25rem;
                flex: 0 0 auto;
                place-items: center;
                border: 1px solid var(--ha-border);
                border-radius: 50%;
                background: var(--ha-soft);
                color: var(--ha-blue);
            }
            .ha-player__icon .ha-icon {
                width: 26px;
                height: 26px;
            }
            .ha-player__details {
                display: grid;
                min-width: 0;
                gap: .12rem;
            }
            .ha-player__current,
            .ha-player__album {
                min-width: 0;
            }
            .ha-player__album {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .ha-player__current {
                font-weight: 700;
                overflow-wrap: anywhere;
                white-space: normal;
            }
            .ha-player__album {
                color: var(--ha-muted);
                font-size: .9rem;
            }
            .ha-player__skip,
            .ha-player__toggle,
            .ha-track__play {
                display: inline-grid;
                width: 44px;
                height: 44px;
                flex: 0 0 auto;
                place-items: center;
                border: 0;
                border-radius: 50%;
                background: var(--ha-blue);
                color: #fff;
                cursor: pointer;
                font: inherit;
                font-size: 1rem;
                font-weight: 700;
                transition: background-color .15s ease, transform .15s ease;
            }
            .ha-player__skip:hover:not(:disabled),
            .ha-player__toggle:hover:not(:disabled),
            .ha-track__play:hover {
                background: var(--ha-blue-dark);
                transform: translateY(-1px);
            }
            .ha-player__skip:disabled,
            .ha-player__toggle:disabled {
                background: #d9e2e7;
                color: #788893;
                cursor: not-allowed;
            }
            .ha-player__skip:focus-visible,
            .ha-player__toggle:focus-visible,
            .ha-player__close:focus-visible,
            .ha-player__seek:focus-visible,
            .ha-album__play:focus-visible,
            .ha-track__play:focus-visible,
            .ha-track__download:focus-visible,
            .ha-album__summary:focus-visible {
                outline: 3px solid var(--ha-blue);
                outline-offset: 3px;
            }
            .ha-player__controls {
                display: grid;
                min-width: 0;
                grid-template-columns: 44px 44px minmax(0, 1fr) 44px;
                align-items: center;
                gap: .5rem;
                padding-right: 3rem;
            }
            .ha-player__transport {
                position: relative;
                min-width: 0;
                height: 60px;
            }
            .ha-player__seek {
                position: absolute;
                top: 18px;
                left: 0;
                width: 100%;
                min-width: 0;
                height: 24px;
                margin: 0;
                accent-color: var(--ha-blue);
                cursor: pointer;
            }
            .ha-player__seek:disabled {
                cursor: default;
            }
            .ha-player__times {
                position: absolute;
                top: 44px;
                right: 0;
                left: 0;
                display: flex;
                justify-content: space-between;
                gap: .5rem;
                color: var(--ha-muted);
                font-size: .75rem;
                font-variant-numeric: tabular-nums;
            }
            .ha-player__toggle .ha-icon,
            .ha-track__play .ha-icon,
            .ha-album__play .ha-icon {
                width: 22px;
                height: 22px;
            }
            .ha-player__audio {
                display: none;
            }
            .ha-album {
                margin: .9rem 0;
                overflow: hidden;
                border: 1px solid var(--ha-border);
                border-radius: 16px;
                background: var(--ha-surface);
                box-shadow: 0 6px 20px rgba(29, 38, 48, .06);
            }
            .ha-album__summary {
                display: flex;
                min-height: 76px;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding: 1rem 1.2rem;
                cursor: pointer;
                list-style: none;
                background: #fff;
            }
            .ha-album__summary::-webkit-details-marker {
                display: none;
            }
            .ha-album__name {
                display: block;
                font-size: 1.12rem;
                font-weight: 700;
            }
            .ha-album__year {
                display: block;
                margin-top: .15rem;
                color: var(--ha-muted);
                font-size: .9rem;
            }
            .ha-album__meta {
                display: flex;
                align-items: center;
                gap: .7rem;
                flex: 0 0 auto;
                color: var(--ha-muted);
                font-size: .9rem;
            }
            .ha-album__icon {
                display: grid;
                width: 2rem;
                height: 2rem;
                place-items: center;
                border-radius: 50%;
                background: var(--ha-soft);
                color: var(--ha-blue);
                transition: transform .18s ease;
            }
            .ha-album__icon .ha-icon {
                width: 18px;
                height: 18px;
            }
            .ha-album[open] .ha-album__icon {
                transform: rotate(180deg);
            }
            .ha-album__play {
                display: inline-flex;
                min-height: 40px;
                align-items: center;
                justify-content: center;
                gap: .45rem;
                margin: 0 1.2rem .5rem;
                padding: .45rem .8rem;
                border: 1px solid var(--ha-border);
                border-radius: 999px;
                background: #fff;
                color: var(--ha-blue-dark);
                cursor: pointer;
                font: inherit;
                font-size: .9rem;
                font-weight: 600;
            }
            .ha-album__play:hover {
                border-color: var(--ha-blue);
                background: var(--ha-soft);
            }
            .ha-track-list {
                margin: 0;
                padding: .25rem 0;
                list-style: none;
            }
            .ha-track {
                display: grid;
                grid-template-columns: 44px minmax(0, 1fr) auto auto;
                align-items: center;
                gap: .65rem;
                padding: .7rem 1.2rem;
                border-bottom: 1px solid #eaf0f3;
                transition: background-color .15s ease;
            }
            .ha-track:last-child {
                border-bottom: 0;
            }
            .ha-track.is-current {
                background: #f3f8fa;
            }
            .ha-track:hover {
                background: #f7fafb;
            }
            .ha-track__play {
                background: #edf4f7;
                color: var(--ha-blue-dark);
            }
            .ha-track__play:hover {
                color: #fff;
            }
            .ha-track__play.is-playing {
                background: var(--ha-blue);
                color: #fff;
            }
            .ha-track__title {
                min-width: 0;
                font-weight: 600;
                overflow-wrap: anywhere;
            }
            .ha-track__duration {
                color: var(--ha-muted);
                font-size: .82rem;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .ha-track__number {
                display: inline-block;
                min-width: 2rem;
                margin-right: .25rem;
                color: var(--ha-muted);
                font-variant-numeric: tabular-nums;
                font-weight: 500;
            }
            .ha-track__download {
                display: inline-flex;
                min-height: 44px;
                align-items: center;
                justify-content: center;
                gap: .35rem;
                padding: .45rem .8rem;
                border: 1px solid #d9e4e9;
                border-radius: 999px;
                background: var(--ha-surface);
                color: var(--ha-blue-dark);
                font-size: .9rem;
                font-weight: 600;
                text-decoration: none;
                white-space: nowrap;
            }
            .ha-albums a.ha-track__download,
            .ha-albums a.ha-track__download:visited,
            .ha-albums a.ha-track__download:hover,
            .ha-albums a.ha-track__download:active,
            .ha-albums a.ha-track__download:focus {
                color: var(--ha-blue) !important;
            }
            .ha-track__download .ha-icon {
                width: 18px;
                height: 18px;
            }
            .ha-track__download:hover {
                border-color: var(--ha-blue);
                background: var(--ha-soft);
                color: var(--ha-blue-dark);
            }
            .ha-sr-only {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                clip-path: inset(50%);
            }
            @media (max-width: 1024px) {
                .ha-albums {
                    margin: 1.25rem 0;
                }
                .ha-player {
                    bottom: max(.5rem, env(safe-area-inset-bottom));
                    width: calc(100vw - 1rem);
                    grid-template-columns: 1fr;
                    gap: .75rem;
                    padding: .8rem;
                }
                .ha-player.has-track {
                    box-shadow: 0 8px 28px rgba(29, 38, 48, .18);
                }
                .ha-player__identity {
                    gap: .65rem;
                    padding-right: 3rem;
                }
                .ha-player__icon {
                    display: none;
                }
                .ha-player__controls {
                    grid-template-columns: 44px 44px minmax(0, 1fr) 44px;
                    gap: .35rem;
                    padding-right: 0;
                }
                .ha-album__summary {
                    padding: .9rem;
                }
                .ha-album__meta {
                    gap: .4rem;
                    font-size: .82rem;
                }
                .ha-album__play {
                    margin-left: .9rem;
                }
                .ha-track-list {
                    padding: .25rem 0;
                }
                .ha-track {
                    grid-template-columns: 44px minmax(0, 1fr) auto 44px;
                    gap: .4rem;
                    padding-right: .9rem;
                    padding-left: .9rem;
                }
                .ha-track__title {
                    display: flex;
                    align-items: center;
                    gap: .15rem;
                }
                .ha-track__number {
                    flex: 0 0 1.6rem;
                    min-width: 0;
                    margin-right: 0;
                }
                .ha-track__name {
                    min-width: 0;
                }
                .ha-track__download {
                    display: grid;
                    width: 44px;
                    height: 44px;
                    align-self: center;
                    padding: 0;
                    place-items: center;
                    font-size: 0;
                }
                .ha-track__download .ha-icon {
                    align-self: center;
                    justify-self: center;
                }
                .ha-track__download > span {
                    display: none;
                }
            }
            @media (prefers-reduced-motion: reduce) {
                .ha-player,
                .ha-player__skip,
                .ha-player__toggle,
                .ha-player__close,
                .ha-album__icon,
                .ha-track__play,
                .ha-track {
                    transition: none;
                }
            }
        </style>
        <script>
            (function () {
                function getCurrentButton(root) {
                    return root.querySelector('.ha-track__play.is-current');
                }

                function getAlbumButtons(button) {
                    var album = button.closest('.ha-album');
                    return album ? Array.from(album.querySelectorAll('.ha-track__play')) : [];
                }

                function formatTime(seconds) {
                    if (!Number.isFinite(seconds) || seconds < 0) {
                        return '0:00';
                    }
                    var totalSeconds = Math.floor(seconds);
                    var minutes = Math.floor(totalSeconds / 60);
                    var remainder = String(totalSeconds % 60).padStart(2, '0');
                    if (minutes >= 60) {
                        return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0') + ':' + remainder;
                    }
                    return minutes + ':' + remainder;
                }

                function updateTimeline(root) {
                    var audio = root.querySelector('[data-ha-audio]');
                    var seek = root.querySelector('[data-ha-seek]');
                    var currentTime = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;
                    var duration = Number.isFinite(audio.duration) ? audio.duration : 0;
                    seek.max = String(duration);
                    seek.value = String(Math.min(currentTime, duration));
                    seek.disabled = !getCurrentButton(root) || duration <= 0;
                    seek.setAttribute('aria-valuetext', formatTime(currentTime) + ' z ' + formatTime(duration));
                    root.querySelector('[data-ha-time-current]').textContent = formatTime(currentTime);
                    root.querySelector('[data-ha-time-duration]').textContent = formatTime(duration);
                }

                function setIconHidden(icon, hidden) {
                    if (hidden) {
                        icon.setAttribute('hidden', '');
                    } else {
                        icon.removeAttribute('hidden');
                    }
                }

                function updatePlayer(root) {
                    var audio = root.querySelector('[data-ha-audio]');
                    var current = getCurrentButton(root);
                    var buttons = root.querySelectorAll('.ha-track__play');
                    var previous = root.querySelector('[data-ha-step="-1"]');
                    var next = root.querySelector('[data-ha-step="1"]');
                    var toggle = root.querySelector('[data-ha-toggle]');
                    var albumButtons = current ? getAlbumButtons(current) : [];
                    var currentIndex = albumButtons.indexOf(current);
                    var playing = Boolean(current && !audio.paused && !audio.ended);

                    buttons.forEach(function (button) {
                        var selected = button === current;
                        var trackPlaying = selected && playing;

                        button.classList.toggle('is-playing', trackPlaying);
                        button.closest('.ha-track').classList.toggle('is-current', selected);
                        button.setAttribute('aria-pressed', trackPlaying ? 'true' : 'false');
                        button.setAttribute('aria-label', (trackPlaying ? 'Pozastaviť ' : 'Prehrať ') + button.dataset.trackTitle);
                        setIconHidden(button.querySelector('[data-ha-icon="play"]'), trackPlaying);
                        setIconHidden(button.querySelector('[data-ha-icon="pause"]'), !trackPlaying);
                    });

                    toggle.disabled = !current;
                    toggle.classList.toggle('is-playing', playing);
                    toggle.setAttribute('aria-pressed', playing ? 'true' : 'false');
                    toggle.setAttribute('aria-label', playing ? 'Pozastaviť skladbu' : 'Prehrať skladbu');
                    setIconHidden(toggle.querySelector('[data-ha-toggle-icon="play"]'), playing);
                    setIconHidden(toggle.querySelector('[data-ha-toggle-icon="pause"]'), !playing);
                    previous.disabled = !current || currentIndex <= 0;
                    next.disabled = !current || currentIndex >= albumButtons.length - 1;
                    updateTimeline(root);
                }

                function showPlaybackError(root, error) {
                    console.error('Skladbu sa nepodarilo prehrať.', error || 'Neznáma chyba prehrávania.');
                    updatePlayer(root);
                }

                function requestPlayback(root, audio) {
                    try {
                        var playRequest = audio.play();
                        if (playRequest && typeof playRequest.catch === 'function') {
                            playRequest.catch(function (error) {
                                if (!error || error.name !== 'AbortError') {
                                    showPlaybackError(root, error);
                                }
                            });
                        }
                    } catch (error) {
                        if (!error || error.name !== 'AbortError') {
                            showPlaybackError(root, error);
                        }
                    }
                }

                function bindAudio(root) {
                    var audio = root.querySelector('[data-ha-audio]');
                    if (audio.dataset.haBound) {
                        return;
                    }
                    audio.dataset.haBound = 'true';
                    audio.addEventListener('play', function () {
                        document.querySelectorAll('[data-ha-audio]').forEach(function (otherAudio) {
                            if (otherAudio !== audio) {
                                otherAudio.pause();
                            }
                        });
                        updatePlayer(root);
                    });
                    audio.addEventListener('pause', function () {
                        updatePlayer(root);
                    });
                    ['loadedmetadata', 'durationchange', 'timeupdate', 'seeked'].forEach(function (eventName) {
                        audio.addEventListener(eventName, function () {
                            updateTimeline(root);
                        });
                    });
                    audio.addEventListener('ended', function () {
                        var current = getCurrentButton(root);
                        var albumButtons = current ? getAlbumButtons(current) : [];
                        var nextIndex = albumButtons.indexOf(current) + 1;
                        if (nextIndex > 0 && nextIndex < albumButtons.length) {
                            startTrack(albumButtons[nextIndex]);
                        } else {
                            updatePlayer(root);
                        }
                    });
                    audio.addEventListener('error', function () {
                        if (getCurrentButton(root)) {
                            showPlaybackError(root, audio.error);
                        }
                    });
                }

                function startTrack(button, forcePlay) {
                    var root = button.closest('[data-ha-root]');
                    var audio = root.querySelector('[data-ha-audio]');
                    var current = getCurrentButton(root);
                    bindAudio(root);

                    if (!forcePlay && button === current && !audio.paused) {
                        audio.pause();
                        return;
                    }

                    document.querySelectorAll('[data-ha-audio]').forEach(function (otherAudio) {
                        if (otherAudio !== audio) {
                            otherAudio.pause();
                        }
                    });

                    if (button !== current) {
                        audio.pause();
                        if (current) {
                            current.classList.remove('is-current');
                        }
                        button.classList.add('is-current');
                        audio.src = button.dataset.src;
                        audio.load();
                        root.querySelector('[data-ha-current]').textContent = button.dataset.trackTitle;
                        root.querySelector('[data-ha-album]').textContent = button.dataset.albumTitle;
                    }

                    root.querySelector('[data-ha-player]').classList.add('has-track');
                    updatePlayer(root);
                    requestPlayback(root, audio);
                }

                function togglePlayback(root) {
                    var audio = root.querySelector('[data-ha-audio]');
                    if (!getCurrentButton(root)) {
                        return;
                    }
                    if (audio.paused) {
                        requestPlayback(root, audio);
                    } else {
                        audio.pause();
                    }
                }

                function closePlayer(root) {
                    var audio = root.querySelector('[data-ha-audio]');
                    var current = getCurrentButton(root);

                    audio.pause();
                    audio.removeAttribute('src');
                    audio.load();
                    if (current) {
                        current.classList.remove('is-current');
                    }
                    root.querySelector('[data-ha-current]').textContent = 'Vyberte skladbu zo zoznamu';
                    root.querySelector('[data-ha-album]').textContent = 'Hudobné albumy Mamateyka';
                    root.querySelector('[data-ha-player]').classList.remove('has-track');
                    updatePlayer(root);
                }

                function refreshStickyScrollTriggers() {
                    window.requestAnimationFrame(function () {
                        if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
                            // Accordion height changes can leave Breakdance's pinned header range stale.
                            window.ScrollTrigger.refresh();
                        }
                    });
                }

                function initializePlayers() {
                    document.querySelectorAll('[data-ha-root]').forEach(function (root) {
                        bindAudio(root);
                        updatePlayer(root);
                        root.querySelectorAll('.ha-album').forEach(function (album) {
                            album.addEventListener('toggle', refreshStickyScrollTriggers);
                        });
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initializePlayers);
                } else {
                    initializePlayers();
                }

                document.addEventListener('click', function (event) {
                    var target = event.target.closest ? event.target : event.target.parentElement;
                    if (!target) {
                        return;
                    }

                    var closeButton = target.closest('[data-ha-close]');
                    if (closeButton) {
                        closePlayer(closeButton.closest('[data-ha-root]'));
                        return;
                    }

                    var playButton = target.closest('.ha-track__play');
                    if (playButton) {
                        startTrack(playButton);
                        return;
                    }

                    var albumButton = target.closest('[data-ha-play-album]');
                    if (albumButton) {
                        var album = albumButton.closest('.ha-album');
                        var firstTrack = album ? album.querySelector('.ha-track__play') : null;
                        if (firstTrack) {
                            startTrack(firstTrack, true);
                        }
                        return;
                    }

                    var toggleButton = target.closest('[data-ha-toggle]');
                    if (toggleButton && !toggleButton.disabled) {
                        togglePlayback(toggleButton.closest('[data-ha-root]'));
                        return;
                    }

                    var stepButton = target.closest('[data-ha-step]');
                    if (stepButton && !stepButton.disabled) {
                        var root = stepButton.closest('[data-ha-root]');
                        var current = getCurrentButton(root);
                        var albumButtons = current ? getAlbumButtons(current) : [];
                        var nextIndex = albumButtons.indexOf(current) + Number(stepButton.dataset.haStep);
                        if (albumButtons[nextIndex]) {
                            startTrack(albumButtons[nextIndex]);
                        }
                    }
                });

                document.addEventListener('input', function (event) {
                    var seek = event.target;
                    if (!seek.matches || !seek.matches('[data-ha-seek]') || seek.disabled) {
                        return;
                    }
                    var root = seek.closest('[data-ha-root]');
                    var audio = root.querySelector('[data-ha-audio]');
                    audio.currentTime = Number(seek.value);
                    updateTimeline(root);
                });
            })();
        </script>
        <?php
    }
    ?>
    <section class="ha-albums" data-ha-root aria-label="Hudobné albumy">
        <div class="ha-player" data-ha-player>
            <div class="ha-player__identity">
                <span class="ha-player__icon" aria-hidden="true">
                    <svg class="ha-icon" viewBox="0 0 24 24" fill="none" focusable="false">
                        <path d="M9 18V5l10-2v13M9 8l10-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="6.5" cy="18" r="2.5" fill="currentColor"/>
                        <circle cx="16.5" cy="16" r="2.5" fill="currentColor"/>
                    </svg>
                </span>
                <span class="ha-player__details">
                    <span class="ha-player__current" data-ha-current>Vyberte skladbu zo zoznamu</span>
                    <span class="ha-player__album" data-ha-album>Hudobné albumy Mamateyka</span>
                </span>
                <button class="ha-player__close" type="button" data-ha-close aria-label="Zavrieť prehrávač a zastaviť prehrávanie">
                    <svg class="ha-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="ha-player__controls">
                <button class="ha-player__skip" type="button" data-ha-step="-1" aria-label="Predchádzajúca skladba" disabled>
                    <svg class="ha-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M6.5 5.5v13M18 6.5 9.5 12l8.5 5.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button class="ha-player__toggle" type="button" data-ha-toggle aria-label="Prehrať skladbu" disabled>
                    <svg class="ha-icon" data-ha-toggle-icon="play" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M8 5.75a1 1 0 0 0-1.5.86v10.78a1 1 0 0 0 1.5.86l9.2-5.39a1 1 0 0 0 0-1.72L8 5.75Z" fill="currentColor"/>
                    </svg>
                    <svg class="ha-icon" data-ha-toggle-icon="pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden>
                        <path d="M8 6.25A1.25 1.25 0 0 1 9.25 5h1.5A1.25 1.25 0 0 1 12 6.25v11.5A1.25 1.25 0 0 1 10.75 19h-1.5A1.25 1.25 0 0 1 8 17.75V6.25Zm5 0A1.25 1.25 0 0 1 14.25 5h1.5A1.25 1.25 0 0 1 17 6.25v11.5A1.25 1.25 0 0 1 15.75 19h-1.5A1.25 1.25 0 0 1 13 17.75V6.25Z" fill="currentColor"/>
                    </svg>
                </button>
                <div class="ha-player__transport">
                    <?php $seek_id = wp_unique_id('ha-seek-'); ?>
                    <label class="ha-sr-only" for="<?php echo esc_attr($seek_id); ?>">Pozícia prehrávania</label>
                    <input class="ha-player__seek" id="<?php echo esc_attr($seek_id); ?>" type="range" min="0" max="0" step="1" value="0" data-ha-seek disabled>
                    <span class="ha-player__times" aria-hidden="true">
                        <span data-ha-time-current>0:00</span>
                        <span data-ha-time-duration>0:00</span>
                    </span>
                </div>
                <button class="ha-player__skip" type="button" data-ha-step="1" aria-label="Nasledujúca skladba" disabled>
                    <svg class="ha-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M17.5 5.5v13M6 6.5 14.5 12 6 17.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <audio class="ha-player__audio" data-ha-audio preload="metadata" aria-label="Prehrávač hudobných albumov"></audio>
            </div>
        </div>
        <?php foreach ($albums as $album) : ?>
            <details class="ha-album">
                <summary class="ha-album__summary">
                    <span>
                        <span class="ha-album__name"><?php echo esc_html($album['title']); ?></span>
                        <span class="ha-album__year"><?php echo esc_html($album['year']); ?></span>
                    </span>
                    <span class="ha-album__meta">
                        <span><?php echo esc_html(count($album['tracks'])); ?> skladieb</span>
                        <span class="ha-album__icon" aria-hidden="true">
                            <svg class="ha-icon" viewBox="0 0 24 24" fill="none" focusable="false">
                                <path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </span>
                </summary>
                <button class="ha-album__play" type="button" data-ha-play-album>
                    <svg class="ha-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M8 5.75a1 1 0 0 0-1.5.86v10.78a1 1 0 0 0 1.5.86l9.2-5.39a1 1 0 0 0 0-1.72L8 5.75Z" fill="currentColor"/>
                    </svg>
                    <span>Prehrať album</span>
                </button>
                <ol class="ha-track-list">
                    <?php foreach ($album['tracks'] as $track) : ?>
                        <?php
                        $filename = sanitize_file_name($track['file']);
                        $url = $base_url . rawurlencode($filename);
                        ?>
                        <li class="ha-track">
                            <button class="ha-track__play" type="button" data-ha-play data-src="<?php echo esc_url($url); ?>" data-track-title="<?php echo esc_attr($track['number'] . '. ' . $track['title']); ?>" data-album-title="<?php echo esc_attr($album['title'] . ' (' . $album['year'] . ')'); ?>" aria-label="<?php echo esc_attr('Prehrať ' . $track['title']); ?>" aria-pressed="false">
                                <svg class="ha-icon" data-ha-icon="play" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M8 5.75a1 1 0 0 0-1.5.86v10.78a1 1 0 0 0 1.5.86l9.2-5.39a1 1 0 0 0 0-1.72L8 5.75Z" fill="currentColor"/>
                                </svg>
                                <svg class="ha-icon" data-ha-icon="pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden>
                                    <path d="M8 6.25A1.25 1.25 0 0 1 9.25 5h1.5A1.25 1.25 0 0 1 12 6.25v11.5A1.25 1.25 0 0 1 10.75 19h-1.5A1.25 1.25 0 0 1 8 17.75V6.25Zm5 0A1.25 1.25 0 0 1 14.25 5h1.5A1.25 1.25 0 0 1 17 6.25v11.5A1.25 1.25 0 0 1 15.75 19h-1.5A1.25 1.25 0 0 1 13 17.75V6.25Z" fill="currentColor"/>
                                </svg>
                            </button>
                            <span class="ha-track__title">
                                <span class="ha-track__number"><?php echo esc_html($track['number']); ?>.</span>
                                <span class="ha-track__name"><?php echo esc_html($track['title']); ?></span>
                            </span>
                            <span class="ha-track__duration"><?php echo esc_html(sprintf('%d:%02d', floor($track['duration'] / 60), $track['duration'] % 60)); ?></span>
                            <a class="ha-track__download" href="<?php echo esc_url($url); ?>" download aria-label="<?php echo esc_attr('Stiahnuť skladbu ' . $track['title']); ?>">
                                <svg class="ha-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                                    <path d="M12 3.75v10.5m0 0 4-4m-4 4-4-4M4.5 16.5v3h15v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>Stiahnuť</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endforeach; ?>
    </section>
    <?php
    return ob_get_clean();
});