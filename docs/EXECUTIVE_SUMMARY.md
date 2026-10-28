# Executive Summary

Date: February 6, 2026
Project: `zpush-jmap`
Audience: CTO, CSO, Operations, and business leadership

## What This Project Is

`zpush-jmap` is an ActiveSync bridge for Stalwart Mail. It allows mobile and desktop clients that support Exchange ActiveSync to work with a JMAP-native backend.

In practical terms:

- Stalwart remains the core mail platform (JMAP/IMAP/SMTP).
- `zpush-jmap` provides ActiveSync compatibility for clients that do not yet support JMAP natively.
- NGINX at the edge terminates TLS on `443` and proxies ActiveSync requests internally.

The current implementation covers mail, contacts, appointments, and ordinary
tasks. Task support includes completion, reminders, and list moves; recurring
and assigned task edits remain outside the initial task implementation. See
[task support](TASKS.md) for the exact boundaries.

Releases use a root VERSION file, isolated local image verification, and an
explicit manual SSH deployment step with backup and rollback. Deployment targets
remain in private local configuration. See [the release workflow](RELEASE_WORKFLOW.md).

## Why We Built It

The strategic direction is JMAP. The operational reality is client support:

- iOS native Mail does not yet provide broad, production-ready JMAP support.
- Many organizations still depend on Exchange-style account setup and ActiveSync behavior.
- Large folder hierarchies (hundreds to thousands of folders) are often more predictable for users through ActiveSync client behavior than through IMAP on mobile.

This project closes that gap now, so teams can keep Stalwart/JMAP as the backend while maintaining reliable mobile client compatibility.

## Business Value

- Preserves user experience on mainstream mobile clients without waiting for ecosystem changes.
- Reduces migration friction from legacy Exchange/Zimbra-style environments.
- Supports incremental modernization: JMAP backend today, native JMAP clients later.
- Protects operational continuity for organizations with complex mailbox structures.

## Security and Risk Posture

Current code/config defaults enforce core transport safeguards:

- TLS verification defaults enabled for non-loopback upstream transport.
- Insecure non-loopback HTTP is blocked by default.
- Request timeout controls are implemented to reduce upstream hang risk.
- Backend listener binding is configurable and defaults to loopback in docs/config.

Security outcome depends on deployment discipline:

- Keep public exposure at the TLS edge (`443`) only.
- Restrict backend listener via bind address and host firewall policy.
- Use lab-only relaxation flags only in non-production environments.

## Deployment Model

- `network_mode: host` is supported for environments that require host firewall enforcement.
- In production, bind to loopback or a dedicated internal service IP, not wildcard.
- Treat `zpush-jmap` as an internal backend behind front-end NGINX/TLS.

## Strategic Outlook

`zpush-jmap` is an interoperability layer, not a long-term replacement for native JMAP client support.
As mobile ecosystems improve JMAP support, organizations can reduce dependency on ActiveSync while retaining the same Stalwart backend.
