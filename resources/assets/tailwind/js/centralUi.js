import {Form} from "@modules/Form/resources/assets/tailwind/js/form";
import {messageBox} from "@modules/Form/resources/assets/tailwind/js/messageBox";
import {user} from "@modules/Form/resources/assets/tailwind/js/user";

/**
 * Uses basic functionality from Website
 */
export class CentralUi {

    /**
     *
     * @type {boolean}
     */
    isLoaded = false;

    /**
     *
     * @type {{hamburgerOpen: boolean}}
     */
    navigation = {
        hamburgerOpen: false,
    };

    /**
     *
     */
    form = null;

    /**
     *
     */
    messageBox = messageBox();

    /**
     * Represents a user.
     * It's not the current user! Can be changed multiple times.
     * Will be used for cross-selling for example.
     */
    user = user();


    callOnReady = function (callback) {
        if (document.readyState !== 'loading') {
            callback();
        } else {
            // @todo: not sure 'alpine:init' should have checked on this place
            ['DOMContentLoaded', 'alpine:init'].forEach(evt => document.addEventListener(evt, () => {
                // ['DOMContentLoaded'].forEach(evt => document.addEventListener(evt, () => {
                callback();
            }));
        }
    };

    /**
     *
     * @param url
     * @returns {Promise<any>}
     */
    requestGet = function (url) {
        return fetch(url).then(res => res.json() ?? {})
    };

    /**
     *
     * @param url
     * @param data
     * @returns {Promise<any>}
     */
    requestPost = function (url, data) {
        return fetch(url, {
            method: 'POST', headers: {
                'Content-Type': 'application/json', // 'Content-Type': 'multipart/form-data',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name=csrf-token]').content
            }, body: JSON.stringify(data)
        }).then(res => res.json());
    };

    startCentralUi = function (classInstance) {

        classInstance.user.start(classInstance);
        classInstance.messageBox.start(classInstance);

        // The VM switch countdown lives in graceCountdown.js now (display only, no business logic here).

    };

    /**
     *
     */
    start = function () {

        let classInstance = this;
        classInstance.startCentralUi(classInstance);


        // Broadcast channels shown by the update pulses (partials/update-bubble.blade.php): channel => event.
        // The leading dot means "event name without namespace prefix" (same as in the Livewire #[On('echo:...')]).
        const broadcastChannels = {
            'proxmox-rrd-updates': '.Modules\\CentralUi\\app\\Events\\ProxmoxApiRrdDataUpdated',
            'proxmox-resource-updates': '.Modules\\CentralUi\\app\\Events\\ProxmoxApiResourceDataUpdated',
            'vm-updates': '.Modules\\CentralUi\\app\\Events\\LocalDesktopUpdated',
        };
        let broadcastBridged = false;

        // One Echo listener per channel for the whole page (not one per card), forwarded as
        // window event 'central-ui:broadcast' with detail.channel. Browser only, no server request.
        const bridgeBroadcasts = () => {
            if (broadcastBridged || !window.Echo) {
                return;
            }
            broadcastBridged = true;

            for (const [channel, event] of Object.entries(broadcastChannels)) {
                window.Echo.channel(channel).listen(event, () => {
                    window.dispatchEvent(new CustomEvent('central-ui:broadcast', {detail: {channel}}));
                });
            }
        };

        const register = () => {
            // Pulse of one broadcast channel (partials/update-bubble.blade.php).
            window.Alpine.data('updateBubble', (channel) => ({
                lastSeen: new Date().toLocaleTimeString(),
                onBroadcast: null,

                init() {
                    bridgeBroadcasts();

                    this.onBroadcast = (event) => {
                        if (event.detail?.channel !== channel) {
                            return;
                        }
                        this.lastSeen = new Date().toLocaleTimeString();
                        this.pulse();
                    };
                    window.addEventListener('central-ui:broadcast', this.onBroadcast);
                },

                // restart the css animation
                pulse() {
                    const el = this.$refs.bubble;
                    if (!el) {
                        return;
                    }
                    el.style.animation = 'none';
                    void el.offsetHeight;
                    el.style.animation = null;
                },

                destroy() {
                    window.removeEventListener('central-ui:broadcast', this.onBroadcast);
                },
            }));

            // total: full length of the grace period. A re-render starts the countdown again with the
            // remaining seconds, but the bar keeps its scale instead of jumping back to 100 %.
            window.Alpine.data('graceCountdown', (remaining, total = null) => ({
                left: remaining,
                total: total || remaining,
                timer: null,

                init() {
                    // Work with a local end time instead of decrementing a counter:
                    // setInterval gets throttled in background tabs, Date.now() does not lie.
                    const end = Date.now() + remaining * 1000;

                    this.timer = setInterval(() => {
                        this.left = Math.max(0, (end - Date.now()) / 1000);

                        if (this.left === 0) {
                            clearInterval(this.timer);
                            this.timer = null;
                            // Fallback in case the broadcast event never arrives:
                            // re-render and show the real state (now "busy").
                            this.$wire.$refresh();
                        }
                    }, 100);
                },

                // Alpine calls destroy() automatically when the element is removed
                // (cancelled, state changed, card re-rendered without the overlay).
                destroy() {
                    if (this.timer) {
                        clearInterval(this.timer);
                    }
                },
            }));

            /**
             * Fallback of the VM desktop while the websocket (Reverb) is not connected:
             * every intervalSeconds all cards refresh by the Livewire event 'vm-desktop:poll'.
             * Connected = no timer, no requests (the cards get pushed events).
             */
            window.Alpine.data('liveUpdateFallback', (intervalSeconds = 20) => ({
                // pusher connection state: initialized, connecting, connected, unavailable, failed, disconnected
                // ('missing' = no Echo on this page)
                state: 'initialized',
                timer: null,
                missedUpdates: false,
                connection: null,
                onStateChange: null,

                // really broken, not just connecting (used for the hint)
                get isBroken() {
                    return ['unavailable', 'failed', 'disconnected', 'missing'].includes(this.state);
                },

                init() {
                    this.connection = window.Echo?.connector?.pusher?.connection ?? null;
                    if (!this.connection) {
                        this.setState('missing');
                        return;
                    }

                    this.onStateChange = (states) => this.setState(states.current);
                    this.connection.bind('state_change', this.onStateChange);
                    this.setState(this.connection.state);
                },

                setState(state) {
                    this.state = state;
                    if (state === 'connected') {
                        this.stopPolling();
                        // reconnected after a gap: catch up once
                        if (this.missedUpdates) {
                            this.missedUpdates = false;
                            this.refresh();
                        }
                    } else {
                        this.startPolling();
                    }
                },

                startPolling() {
                    if (this.timer) {
                        return;
                    }
                    this.timer = setInterval(() => {
                        this.missedUpdates = true;
                        this.refresh();
                    }, intervalSeconds * 1000);
                },

                stopPolling() {
                    if (this.timer) {
                        clearInterval(this.timer);
                        this.timer = null;
                    }
                },

                refresh() {
                    // no requests from background tabs
                    if (!document.hidden) {
                        window.Livewire.dispatch('vm-desktop:poll');
                    }
                },

                destroy() {
                    this.stopPolling();
                    if (this.connection && this.onStateChange) {
                        this.connection.unbind('state_change', this.onStateChange);
                    }
                },
            }));
        };

        // Register before Alpine starts. If Livewire already exposed Alpine, register right away,
        // otherwise wait for the 'alpine:init' event.
        if (window.Alpine) {
            register();
        } else {
            document.addEventListener('alpine:init', register);
        }


    };

    /**
     *
     * @param el
     */
    scrollTo = function (el) {
        let elm = document.querySelector(el);
        window.scrollTo(elm.offsetLeft, elm.offsetTop);
    };

    /**
     *
     */
    scrollToForm = function () {
        let classInstance = this;
        // classInstance.scrollTo('.website-base-dt-forms');
        classInstance.scrollTo('body');
    };

    /**
     * get value using dot notation
     *
     * @param t
     * @param path
     * @param defaultResult
     * @returns {string}
     */
    getValue = function (t, path, defaultResult) {
        let result = path.split(".").reduce((r, k) => r?.[k], t);
        if (!result) {
            // result = '[???]';
            // result = path;
            result = defaultResult ?? '';
        }
        return result;
    };

    /**
     *
     * @param key
     * @param replace
     * @returns {*}
     */
    trans = function (key, replace = {}) {
        let classInstance = this;
        var translation = classInstance.getValue(window.translations[window.currentLocale], key);
        // var translation = key.split('.').reduce((t, i) => t[i] || null, window.translations[window.currentLocale]);

        if (!translation) {
            return key;
        }

        for (var placeholder in replace) {
            translation = translation.replace(`:${placeholder}`, replace[placeholder]);
        }
        return translation;
    };

    /**
     *
     * @param modelName
     * @returns {Form}
     */
    getNewForm = function (modelName) {
        return new Form(modelName);
    }

    /**
     *
     * @returns {string}
     */
    getViewport = function (el) {
        let width = Math.max(document.documentElement.clientWidth, window.innerWidth || 0)

        if (typeof el !== "undefined") {
            width = el.offsetWidth;
        }

        // xs - size less than or 575.98
        if (width <= 575.98) return 'xs'
        // sm - size between 576 - 767.98
        if (width >= 576 && width <= 767.98) return 'sm'
        // md - size between 768 - 991.98
        if (width >= 768 && width <= 991.98) return 'md'
        // lg - size between 992 - 1199.98
        if (width >= 992 && width <= 1199.98) return 'lg'
        // xl - size between 1200 - 1399.98
        if (width >= 1200 && width <= 1399.98) return 'xl'

        // xxl- size greater than 1399.98
        return 'xxl'
    }

    /**
     *
     * @returns {number}
     */
    getViewportNumber = function () {
        let classInstance = this;
        switch (classInstance.getViewport()) {
            case 'xs':
                return 1;
            case 'sm':
                return 2;
            case 'md':
                return 3;
            case 'lg':
                return 4;
            case 'xl':
                return 5;
        }
        return 6;
    }

    /**
     *
     * @param arr
     * @param value
     * @returns {boolean}
     */
    isInObject = function (arr, value) {
        if (arr === null) return false;
        if (Array.isArray(arr)) {
            let index = arr.indexOf(value);
            return index !== -1;
        } else if (typeof arr === 'object') {
            return value in arr;
        }
    }

    /**
     *
     * @param arr
     * @param value
     */
    removeFromObject = function (arr, value) {
        if (Array.isArray(arr)) {
            let index = arr.indexOf(value);
            if (index !== -1) {
                arr = arr.splice(index, 1);
            }
        } else if (typeof arr === 'object') {
            delete arr[value];
        }
    }

    addToObject = function (arr, key, value) {
        if (Array.isArray(arr)) {
            arr.push(value);
        } else if (typeof arr === 'object') {
            arr[value] = value;
        }
    }

    /**
     *
     * @param values
     * @param elClassName
     */
    dumpToElement(values, elClassName = 'dump-area') {
        let data = values;
        let el = document.getElementsByClassName(elClassName);
        // console.log(el);
        for (let el2 of el) {
            el2.innerHTML = JSON.stringify(data, null, 2);
        }
    };


} // no ; at the end of class declaration