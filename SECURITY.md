# Security Policy

## Reporting a vulnerability

**Do not open a public issue for security problems.**

Report them privately through GitHub:
**Security → Report a vulnerability** on
[BiztechEG/laravel-fawaterk](https://github.com/BiztechEG/laravel-fawaterk/security/advisories/new).

Please include:

- what an attacker could do, and under which conditions
- steps or a proof of concept to reproduce it
- the package, Laravel and PHP versions you used

We will confirm that we received your report, keep you updated while we work on a fix, and credit you in the
advisory if you wish. Please give us reasonable time to release a fix before you disclose the issue publicly.

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes (once released) |
| < 1.0 | no (development versions) |

## Scope

In scope: anything in this package that could let someone mark an order as paid without paying, pay less than the
order total, replay a payment, reach an endpoint that should not be exposed, read secrets or customer data, or make
the package send credentials to a host other than Fawaterk's.

Out of scope: the security of Fawaterk's own platform (report that to Fawaterk), and problems caused by
configurations this package documents as unsafe.
