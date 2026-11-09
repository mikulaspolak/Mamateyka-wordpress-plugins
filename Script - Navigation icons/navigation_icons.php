<?php

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <style>
        nav.breakdance-menu--offcanvas .mamateyka-mobile-menu-icon {
            display: none;
        }

        @media (max-width: 1023px) {
            nav.breakdance-menu--offcanvas .breakdance-menu-list > li.breakdance-menu-item > a.breakdance-menu-link {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                gap: 1rem;
                padding-left: 1rem;
            }

            nav.breakdance-menu--offcanvas .mamateyka-mobile-menu-icon {
                display: inline-flex;
                flex: 0 0 1.25rem;
                width: 1.25rem;
                height: 1.25rem;
                color: var(--bde-brand-primary-color, #537891);
                line-height: 0;
            }

            nav.breakdance-menu--offcanvas .mamateyka-mobile-menu-icon svg {
                display: block;
                width: 100%;
                height: 100%;
                fill: none;
                stroke: currentColor;
                stroke-width: 2;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
        }
    </style>
    <script>
        (function () {
            var icons = {
                '/': [
                    { type: 'path', d: 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8' },
                    { type: 'path', d: 'M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }
                ],
                '/farnost': [
                    { type: 'path', d: 'M12.0002 8L8.79795 9.5611C8.14576 9.87904 7.81967 10.038 7.58124 10.278C7.37041 10.4903 7.20988 10.7471 7.11148 11.0297C7.0002 11.3492 7.0002 11.7119 7.0002 12.4375V21H17.0002V12.4375C17.0002 11.7119 17.0002 11.3492 16.8889 11.0297C16.7905 10.7471 16.63 10.4903 16.4192 10.278C16.1807 10.038 15.8546 9.87904 15.2024 9.5611L12.0002 8ZM12.0002 8V3M14.0002 5H10.0002M7.0002 13L4.76897 14.1156C4.12683 14.4366 3.80576 14.5971 3.57118 14.8366C3.36374 15.0484 3.20598 15.3037 3.10931 15.5839C3 15.9009 3 16.2598 3 16.9778V21H21V16.9777C21 16.2598 21 15.9008 20.8907 15.5839C20.794 15.3037 20.6363 15.0484 20.4289 14.8366C20.1943 14.5971 19.8732 14.4366 19.2311 14.1155L17.0002 13M14.0002 21V17C14.0002 15.8954 13.1048 15 12.0002 15C10.8956 15 10.0002 15.8954 10.0002 17V21H14.0002Z' }
                ],
                '/piliere': [
                    { type: 'path', d: 'M17 21v-1a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v1' },
                    { type: 'path', d: 'M19 10h1a2 2 0 0 1 2 2v1' },
                    { type: 'path', d: 'M5 10H4a2 2 0 0 0-2 2v1' },
                    { type: 'circle', cx: '12', cy: '11', r: '3' },
                    { type: 'circle', cx: '18', cy: '4', r: '2' },
                    { type: 'circle', cx: '6', cy: '4', r: '2' }
                ],
                '/cinnost': [
                    { type: 'path', d: 'M11 7a16 16 20 0 1 10.98 4.362' },
                    { type: 'path', d: 'M12 12a13 13 0 0 1-8.66 5' },
                    { type: 'path', d: 'M16.83 13.634a16 16 0 0 1-9.267 7.328' },
                    { type: 'path', d: 'M20.66 17A13 13 0 0 0 12 12a13 13 0 0 1 0-10' },
                    { type: 'path', d: 'M8.17 15.366a16 16 0 0 1-1.713-11.69' },
                    { type: 'circle', cx: '12', cy: '12', r: '10' }
                ],
                '/aktuality': [
                    { type: 'path', d: 'M15 18h-5' },
                    { type: 'path', d: 'M18 14h-8' },
                    { type: 'path', d: 'M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-4 0v-9a2 2 0 0 1 2-2h2' },
                    { type: 'rect', width: '8', height: '4', x: '10', y: '6', rx: '1' }
                ],
                '/o-nas': [
                    { type: 'path', d: 'M15 22a1 1 0 0 1-1-1v-4a1 1 0 0 1 .445-.832l3-2a1 1 0 0 1 1.11 0l3 2A1 1 0 0 1 22 17v4a1 1 0 0 1-1 1z' },
                    { type: 'path', d: 'M18 10a8 8 0 0 0-16 0c0 4.993 5.539 10.193 7.399 11.799a1 1 0 0 0 .601.2' },
                    { type: 'path', d: 'M18 22v-3' },
                    { type: 'circle', cx: '10', cy: '10', r: '3' }
                ],
                '/kontakt': [
                    { type: 'path', d: 'M16 2v2' },
                    { type: 'path', d: 'M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2' },
                    { type: 'path', d: 'M8 2v2' },
                    { type: 'circle', cx: '12', cy: '10', r: '3' },
                    { type: 'rect', x: '3', y: '3', width: '18', height: '18', rx: '2' }
                ]
            };
            var namespace = 'http://www.w3.org/2000/svg';
            var selector = 'nav.breakdance-menu--offcanvas .breakdance-menu-list > li.breakdance-menu-item > a.breakdance-menu-link';

            function addMenuIcons() {
                var links = document.querySelectorAll(selector);

                links.forEach(function (link) {
                    var path = link.pathname.replace(/\/+$/, '') || '/';
                    var iconElements = icons[path];

                    if (!iconElements || link.querySelector('.mamateyka-mobile-menu-icon')) {
                        return;
                    }

                    var wrapper = document.createElement('span');
                    wrapper.className = 'mamateyka-mobile-menu-icon';
                    wrapper.setAttribute('aria-hidden', 'true');

                    var svg = document.createElementNS(namespace, 'svg');
                    svg.setAttribute('viewBox', '0 0 24 24');
                    svg.setAttribute('focusable', 'false');

                    iconElements.forEach(function (elementData) {
                        var iconElement = document.createElementNS(namespace, elementData.type);

                        Object.keys(elementData).forEach(function (attribute) {
                            if (attribute !== 'type') {
                                iconElement.setAttribute(attribute, elementData[attribute]);
                            }
                        });

                        svg.appendChild(iconElement);
                    });

                    wrapper.appendChild(svg);
                    link.insertBefore(wrapper, link.firstChild);
                });
            }

            addMenuIcons();

            if (document.readyState === 'complete' && document.querySelector(selector)) {
                return;
            }

            var observer = new MutationObserver(function () {
                addMenuIcons();

                if (document.readyState === 'complete' && document.querySelector(selector)) {
                    observer.disconnect();
                }
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });

            if (document.readyState !== 'complete') {
                window.addEventListener('load', function () {
                    addMenuIcons();

                    if (document.querySelector(selector)) {
                        observer.disconnect();
                    }
                }, { once: true });
            }
        })();
    </script>
    <?php
}, 20);