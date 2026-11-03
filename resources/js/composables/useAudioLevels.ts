import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Live voice levels of a call, for the softphone waveform: the caller's
 * audio (remote stream) and the agent's microphone, mixed bar by bar.
 *
 * `levels` holds one value per bar between 0 and 1. It stays empty when the
 * browser cannot analyse the audio (the waveform then animates on its own).
 */
export function useAudioLevels(
    streams: Ref<(MediaStream | null | undefined)[]>,
    active: Ref<boolean>,
    bars = 22,
) {
    const levels = ref<number[]>([]);

    let context: AudioContext | null = null;
    let analysers: AnalyserNode[] = [];
    let frame = 0;
    let lastPaint = 0;

    function stop() {
        cancelAnimationFrame(frame);
        frame = 0;
        analysers = [];
        void context?.close().catch(() => undefined);
        context = null;
        levels.value = [];
    }

    function paint(time: number) {
        frame = requestAnimationFrame(paint);

        // ~20 updates per second is plenty for a voice meter.
        if (time - lastPaint < 50 || analysers.length === 0) {
            return;
        }

        lastPaint = time;

        const mixed: number[] = Array.from({ length: bars }, () => 0);

        for (const analyser of analysers) {
            const data = new Uint8Array(analyser.frequencyBinCount);
            analyser.getByteFrequencyData(data);

            // Voice sits in the lower bins: spread them over the bars.
            const usable = Math.floor(data.length * 0.6);
            const perBar = Math.max(1, Math.floor(usable / bars));

            for (let bar = 0; bar < bars; bar++) {
                let sum = 0;

                for (let i = 0; i < perBar; i++) {
                    sum += data[bar * perBar + i] ?? 0;
                }

                mixed[bar] = Math.max(mixed[bar], sum / perBar / 255);
            }
        }

        // Speech rarely fills the scale: boost it a little.
        levels.value = mixed.map((level) => Math.min(1, level * 1.6));
    }

    function start(list: MediaStream[]) {
        stop();

        const AudioContextClass =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (!AudioContextClass || list.length === 0) {
            return;
        }

        try {
            context = new AudioContextClass();
            analysers = list
                .filter((stream) => stream.getAudioTracks().length > 0)
                .map((stream) => {
                    const analyser = context!.createAnalyser();
                    analyser.fftSize = 128;
                    analyser.smoothingTimeConstant = 0.7;
                    // Not connected to the speakers: the <audio> element plays the call.
                    context!.createMediaStreamSource(stream).connect(analyser);

                    return analyser;
                });
            void context.resume().catch(() => undefined);
            frame = requestAnimationFrame(paint);
        } catch (error) {
            console.warn('[Softphone] Niveaux audio indisponibles :', error);
            stop();
        }
    }

    watch(
        [streams, active],
        ([list, isActive]) => {
            const usable = list.filter(
                (stream): stream is MediaStream =>
                    stream instanceof MediaStream,
            );

            if (isActive && usable.length > 0) {
                start(usable);
            } else {
                stop();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return { levels };
}
