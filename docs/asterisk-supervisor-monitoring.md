# Asterisk supervisor monitoring

The web application uses Asterisk Manager Interface (AMI) for live channel status and to originate a supervisor call into `ChanSpy`. AMI credentials never reach the browser.

## 1. Configure AMI

In `manager.conf`, create a dedicated account with only the permissions required by the application:

```ini
[callcenter]
secret = CHANGE_THIS_TO_A_LONG_RANDOM_SECRET
read = system,call
write = originate,call
```

Reload the manager interface after changing the file:

```text
manager reload
```

Set these Laravel environment variables:

```dotenv
ASTERISK_AMI_HOST=127.0.0.1
ASTERISK_AMI_PORT=5038
ASTERISK_AMI_USERNAME=callcenter
ASTERISK_AMI_SECRET=CHANGE_THIS_TO_A_LONG_RANDOM_SECRET
ASTERISK_MONITOR_CONTEXT=supervisor-monitor
ASTERISK_MONITOR_EXTENSION=spy
ASTERISK_MONITOR_CALLER_ID="Supervisor Monitor"
ASTERISK_AMI_ORIGINATE_TIMEOUT=30000
```

## 2. Add the monitoring dialplan

The application originates a call to the supervisor's own PJSIP endpoint. Once the supervisor answers, Asterisk enters `ChanSpy` against the selected agent extension.

Add this context to the dialplan included by Asterisk:

```asterisk
[supervisor-monitor]
exten => spy,1,NoOp(Callcenter supervisor monitoring: target=${SPY_TARGET} whisper=${SPY_WHISPER})
 same => n,Answer()
 same => n,Set(SPY_OPTIONS=q${IF($["${SPY_WHISPER}" = "1"]?w:)})
 same => n,ChanSpy(PJSIP/${SPY_TARGET},${SPY_OPTIONS})
 same => n,Hangup()
```

Reload the dialplan:

```text
dialplan reload
```

`Listen` starts normal `ChanSpy`; `Whisper` adds the `w` option, allowing the supervisor to speak to the monitored agent without being heard by the customer.

## 3. Supervisor workflow

1. A supervisor/admin opens **Monitoring**.
2. Agent status refreshes every three seconds.
3. When an agent has a live PJSIP channel, the status becomes **Busy**.
4. The supervisor selects **Listen** or **Whisper**.
5. Asterisk rings the supervisor's SIP/WebRTC endpoint with the `Supervisor Monitor` caller ID.
6. The supervisor answers the incoming call; Asterisk starts `ChanSpy`.

The supervisor must have a SIP extension and be registered in the same Asterisk instance.
