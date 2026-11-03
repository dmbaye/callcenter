import { usePage } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import { getEcho } from '@/lib/echo';

export type InboundContext = {
    number: string;
    queue: string;
    choice: string | null;
    waitSeconds: number | null;
    contact: {
        name: string | null;
        company: string | null;
        campaign: string | null;
        lastDisposition: string | null;
    } | null;
    history: {
        calls90d: number;
        missed90d: number;
        lastCallAt: string | null;
        lastOutcome: string | null;
        lastNotes: string | null;
    };
    receivedAt: number;
};

/** Latest screen pop pushed for this agent (shared by every caller of the composable). */
const offer = ref<InboundContext | null>(null);

/**
 * Screen pop for inbound queue calls: the server pushes who is calling,
 * from which queue / IVR choice, and their history, while the phone rings.
 * Call `listen()` once from a component that stays mounted (the softphone).
 */
export function useInboundContext() {
    const page = usePage();
    let channel: string | null = null;

    const onOffered = (context: Omit<InboundContext, 'receivedAt'>) => {
        offer.value = { ...context, receivedAt: Date.now() };
    };

    function stop() {
        if (channel) {
            getEcho()
                ?.private(channel)
                .stopListening('.inbound.offered', onOffered);
            channel = null;
        }
    }

    function listen() {
        watch(
            () => page.props.auth?.user?.id,
            (userId) => {
                stop();
                const echo = getEcho();

                if (echo && userId) {
                    channel = `users.${userId}`;
                    echo.private(channel).listen('.inbound.offered', onOffered);
                }
            },
            { immediate: true },
        );

        onUnmounted(stop);
    }

    /** The pop for the ringing call: same number (last 8 digits) and recent. */
    function contextFor(number: string): InboundContext | null {
        const current = offer.value;

        if (!current || Date.now() - current.receivedAt > 120_000) {
            return null;
        }

        const tail = (value: string) => value.replace(/\D/g, '').slice(-8);

        return !number || tail(number) === tail(current.number)
            ? current
            : null;
    }

    return { offer, listen, contextFor };
}
