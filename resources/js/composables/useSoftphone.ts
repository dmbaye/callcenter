import { ref } from 'vue';

// Shared UI state for the global softphone panel (rendered by GlobalIncomingCall).
const open = ref(false);
const number = ref('');

export function useSoftphone() {
    /** Open the dialer, optionally pre-filling a number (e.g. "rappeler"). */
    const openDialer = (prefill?: string) => {
        if (prefill) {
            number.value = prefill.replace(/[^\d*#+]/g, '');
        }

        open.value = true;
    };

    const closeDialer = () => {
        open.value = false;
    };

    const toggleDialer = () => {
        open.value = !open.value;
    };

    return { open, number, openDialer, closeDialer, toggleDialer };
}
