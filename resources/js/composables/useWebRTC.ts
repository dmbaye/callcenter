import { computed, reactive } from 'vue';
import {
    Invitation,
    Inviter,
    SessionState,
    UserAgent,
    Registerer,
    RegistererState,
} from 'sip.js';

import type { AgentAvailability, CallStatus, SipCredentials } from '@/types';

export type EndedCall = {
    id: number;
    caller: string;
    name: string;
    direction: 'inbound' | 'outbound';
    startedAt: number;
    endedAt: number;
    duration: number;
};

const callState = reactive({
    registered: false,
    inCall: false,
    incomingCall: false,
    status: 'idle' as CallStatus,

    remoteNumber: '',
    remoteName: '',

    remoteStream: null as MediaStream | null,
    direction: 'inbound' as 'inbound' | 'outbound',
    startedAt: null as number | null,
    /**
     * Calls that ended and still wait for their outcome, oldest first. An
     * agent who takes another call before qualifying the previous one keeps
     * both: the next ended call queues behind instead of replacing it.
     */
    endedCalls: [] as EndedCall[],
    /** Why the last outgoing call did not go through (busy, offline…). */
    callFailure: null as { code: number; at: number } | null,
    /** The oldest call waiting for its outcome (endedCalls[0]). */
    lastEndedCall: null as EndedCall | null,

    session: null as any,
    incomingSession: null as Invitation | null,

    muted: false,
    onHold: false,
});

let userAgent: UserAgent | null = null;
let registerer: Registerer | null = null;
let currentCredentials: SipCredentials | null = null;

const availability = computed<AgentAvailability>(() => {
    if (!callState.registered) {
        return 'offline';
    }

    if (callState.incomingCall) {
        return 'ringing';
    }

    if (callState.inCall) {
        return 'busy';
    }

    return 'available';
});

const sanitizeDomain = (rawDomain?: string): string => {
    if (!rawDomain) {
        return 'asterisk.local';
    }

    return rawDomain
        .replace(/^(http|https|ws|wss):\/\//, '')
        .split(':')[0]
        .split('/')[0];
};

const resetCallState = () => {
    callState.inCall = false;
    callState.incomingCall = false;
    callState.session = null;
    callState.incomingSession = null;
    callState.remoteStream = null;
    callState.remoteNumber = '';
    callState.remoteName = '';
    callState.status = 'idle';

    callState.muted = false;
    callState.onHold = false;
    callState.direction = 'inbound';
    callState.startedAt = null;
};

/** Done with an ended call (saved or skipped): the next one, if any, comes up. */
const dismissLastCall = (id?: number) => {
    callState.endedCalls =
        id === undefined
            ? callState.endedCalls.slice(1)
            : callState.endedCalls.filter((call) => call.id !== id);
    callState.lastEndedCall = callState.endedCalls[0] ?? null;
};

let endedCallId = 0;

/** `ab12cd34ef_7002` (a PJSIP endpoint of the team) → `7002`; other numbers unchanged. */
export const internalExtension = (user: string): string =>
    user.match(/^[a-z0-9]+_(\d+)$/i)?.[1] ?? user;

const captureEndedCall = () => {
    if (!callState.startedAt || !callState.remoteNumber) {
        return;
    }

    const endedAt = Date.now();

    callState.endedCalls.push({
        id: ++endedCallId,
        caller: callState.remoteNumber,
        name: callState.remoteName,
        direction: callState.direction,
        startedAt: callState.startedAt,
        endedAt,
        duration: Math.max(
            0,
            Math.round((endedAt - callState.startedAt) / 1000),
        ),
    });
    callState.lastEndedCall = callState.endedCalls[0] ?? null;
};

const setupRemoteMedia = (session: any) => {
    session.stateChange.addListener((newState: SessionState) => {
        console.log('[SIP] Session state:', newState);

        if (newState === SessionState.Establishing) {
            callState.status = 'connecting';
        }

        if (newState === SessionState.Established) {
            callState.inCall = true;
            callState.incomingCall = false;
            callState.status = 'active';
            callState.startedAt = callState.startedAt ?? Date.now();

            const peerConnection =
                session.sessionDescriptionHandler?.peerConnection;

            if (!peerConnection) {
                console.warn('[SIP] No peer connection available.');
                return;
            }

            const mediaStream = new MediaStream();

            peerConnection
                .getReceivers()
                .forEach((receiver: RTCRtpReceiver) => {
                    if (receiver.track) {
                        mediaStream.addTrack(receiver.track);
                    }
                });

            console.log('[SIP] Remote tracks:', mediaStream.getTracks().length);

            callState.remoteStream = mediaStream;
        }

        if (newState === SessionState.Terminated) {
            console.log('[SIP] Session terminated');

            captureEndedCall();
            resetCallState();
        }
    });
};

export function useWebRTC() {
    const initSip = (sipCredentials: SipCredentials) => {
        if (userAgent) {
            console.log('[SIP] Already initialized, skipping.');
            return;
        }

        currentCredentials = sipCredentials;

        const username = sipCredentials.pjsip_name ?? sipCredentials.username;

        const cleanDomain = sanitizeDomain(
            sipCredentials.domain || sipCredentials.host,
        );

        const uriString = `sip:${username}@${cleanDomain}`;

        const uri = UserAgent.makeURI(uriString);

        if (!uri) {
            console.error('[SIP] Invalid SIP URI:', uriString);

            return;
        }

        // Prefer the full WebSocket URL from config (use wss:// behind HTTPS).
        const websocketServer =
            sipCredentials.wsServer || `wss://${cleanDomain}:8089/ws`;

        console.log('[SIP] Connecting to:', websocketServer);

        console.log('[SIP] SIP URI:', uriString);

        userAgent = new UserAgent({
            uri,

            authorizationUsername: username,
            authorizationPassword: sipCredentials.password,

            transportOptions: {
                server: websocketServer,
                connectionTimeout: 10,
            },

            sessionDescriptionHandlerFactoryOptions: {
                iceGatheringTimeout: 1000,
                peerConnectionConfiguration: {
                    // STUN/TURN come from config/asterisk.php (ASTERISK_STUN_URL, ASTERISK_TURN_*).
                    iceServers: sipCredentials.iceServers ?? [],
                },
            },
        });

        userAgent.delegate = {
            onInvite(invitation: Invitation) {
                console.log('[SIP] Incoming call');

                callState.incomingSession = invitation;

                callState.session = invitation;

                callState.incomingCall = true;

                callState.inCall = false;

                callState.status = 'ringing';

                try {
                    const remoteIdentity = invitation.remoteIdentity;

                    callState.remoteName = remoteIdentity?.displayName || '';

                    // A colleague calls from their endpoint `{team key}_{extension}`:
                    // keep the extension, as when you dial them.
                    callState.remoteNumber = internalExtension(
                        remoteIdentity?.uri?.user || '',
                    );
                    callState.direction = 'inbound';

                    console.log('[SIP] Caller:', {
                        name: callState.remoteName,
                        number: callState.remoteNumber,
                    });
                } catch (error) {
                    console.warn(
                        '[SIP] Unable to read caller identity:',
                        error,
                    );
                }

                setupRemoteMedia(invitation);
            },
        };

        callState.status = 'registering';

        userAgent
            .start()
            .then(async () => {
                if (!userAgent) {
                    return;
                }

                registerer = new Registerer(userAgent);

                // Follow the real registration state (expiry, network loss, server
                // reject): queue presence is driven by callState.registered.
                registerer.stateChange.addListener((state) => {
                    callState.registered = state === RegistererState.Registered;

                    if (!callState.inCall && !callState.incomingCall) {
                        callState.status = callState.registered
                            ? 'registered'
                            : 'idle';
                    }
                });

                await registerer.register();

                console.log('[SIP] REGISTER sent for', username);
            })
            .catch((error) => {
                console.error('[SIP] Failed to start/register:', error);

                callState.registered = false;

                callState.status = 'idle';
            });
    };

    const makeCall = async (targetExtension: string) => {
        if (!userAgent) {
            console.error('[SIP] UserAgent is not initialized.');

            return;
        }

        if (!targetExtension) {
            return;
        }

        if (
            callState.session &&
            callState.status !== 'idle' &&
            callState.status !== 'ended'
        ) {
            console.warn('[SIP] A call is already in progress.');

            return;
        }

        const cleanDomain = sanitizeDomain(
            currentCredentials?.domain || currentCredentials?.host,
        );

        const targetString = `sip:${targetExtension}@${cleanDomain}`;

        const target = UserAgent.makeURI(targetString);

        if (!target) {
            console.error('[SIP] Invalid target URI:', targetString);

            return;
        }

        const session = new Inviter(userAgent, target);

        callState.session = session;

        callState.incomingCall = false;

        callState.inCall = false;

        callState.remoteNumber = targetExtension;
        callState.direction = 'outbound';
        callState.status = 'calling';

        setupRemoteMedia(session);

        try {
            await session.invite({
                requestDelegate: {
                    onReject: (response) => {
                        callState.callFailure = {
                            code: response.message.statusCode ?? 0,
                            at: Date.now(),
                        };
                    },
                },
            });
        } catch (error) {
            console.error('[SIP] INVITE failed:', error);

            resetCallState();
        }
    };

    const answerCall = async () => {
        const session = callState.incomingSession;

        if (!session) {
            console.warn('[SIP] No incoming call to answer.');

            return;
        }

        callState.status = 'connecting';

        try {
            await session.accept();
        } catch (error) {
            console.error('[SIP] Failed to answer call:', error);

            resetCallState();
        }
    };

    const rejectCall = async () => {
        const session = callState.incomingSession;

        if (!session) {
            return;
        }

        try {
            await session.reject();
        } catch (error) {
            console.error('[SIP] Failed to reject call:', error);

            resetCallState();
        }
    };

    const sendDTMF = async (digit: string) => {
        const session = callState.session;

        if (!session) {
            return;
        }

        if (session.state !== SessionState.Established) {
            console.warn('[SIP] Cannot send DTMF before call is established.');
            return;
        }

        try {
            const handler = session.sessionDescriptionHandler;

            if (handler && typeof handler.sendDtmf === 'function') {
                await handler.sendDtmf(digit);

                console.log('[SIP] DTMF:', digit);
            } else {
                console.warn('[SIP] DTMF is not supported.');
            }
        } catch (error) {
            console.error('[SIP] DTMF error:', error);
        }
    };

    const hangup = async () => {
        const session = callState.session;

        if (!session) {
            return;
        }

        try {
            if (
                session instanceof Invitation &&
                session.state === SessionState.Initial
            ) {
                // Still ringing: decline instead of dispose().
                await session.reject();
            } else if (session.state === SessionState.Established) {
                await session.bye();
            } else if (
                session.state === SessionState.Initial ||
                session.state === SessionState.Establishing
            ) {
                await session.cancel();
            } else {
                session.dispose();
            }
        } catch (error) {
            console.error('[SIP] Hangup error:', error);

            resetCallState();
        }
    };

    const disconnect = async () => {
        try {
            if (registerer) {
                await registerer.unregister();
            }

            if (userAgent) {
                await userAgent.stop();
            }
        } catch (error) {
            console.error('[SIP] Disconnect error:', error);
        }

        userAgent = null;
        registerer = null;
        currentCredentials = null;

        callState.registered = false;

        resetCallState();
    };

    const toggleMute = () => {
        const session = callState.session;

        if (!session) {
            console.warn('[SIP] No active session to mute.');
            return;
        }

        if (session.state !== SessionState.Established) {
            console.warn('[SIP] Cannot mute before call is established.');
            return;
        }

        const peerConnection =
            session.sessionDescriptionHandler?.peerConnection;

        if (!peerConnection) {
            console.warn('[SIP] No peer connection available.');
            return;
        }

        const newMutedState = !callState.muted;

        peerConnection.getSenders().forEach((sender: RTCRtpSender) => {
            if (sender.track?.kind === 'audio') {
                sender.track.enabled = !newMutedState;
            }
        });

        callState.muted = newMutedState;

        console.log('[SIP] Microphone:', callState.muted ? 'muted' : 'unmuted');
    };

    const toggleHold = async () => {
        const session = callState.session;

        if (!session) {
            console.warn('[SIP] No active session to hold.');
            return;
        }

        if (session.state !== SessionState.Established) {
            console.warn('[SIP] Cannot hold before call is established.');
            return;
        }

        try {
            const newHoldState = !callState.onHold;

            console.log(
                '[SIP] Changing hold state:',
                newHoldState ? 'hold' : 'resume',
            );

            await session.invite({
                sessionDescriptionHandlerOptions: {
                    hold: newHoldState,
                },
            });

            callState.onHold = newHoldState;

            console.log('[SIP] Call:', newHoldState ? 'en attente' : 'resumed');
        } catch (error) {
            console.error('[SIP] Failed to change hold state:', error);
        }
    };

    return {
        callState,
        availability,

        initSip,
        makeCall,
        answerCall,
        rejectCall,
        hangup,
        sendDTMF,
        toggleMute,
        toggleHold,
        disconnect,
        dismissLastCall,
    };
}
