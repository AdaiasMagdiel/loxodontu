# Project philosophy

Loxodontu is intentionally self-hosted and built entirely on PHP. Docker, serverless
platforms, managed databases, and modern edge runtimes have changed how many teams
deploy software, but they are still not the most accessible path for everyone. In many
places, and for many independent developers, shared PHP hosting remains the cheapest,
simplest, or only realistic way to put an application online.

That constraint shapes the project. Features that are commonly built around newer
runtimes may look different here. Edge functions, for example, are lightweight PHP
scripts, even though similar systems often use Deno or other runtimes. That choice
is deliberate: on shared hosting, extra languages, background services, containers,
and process managers are often unavailable or impractical.

The goal is not to ignore modern infrastructure, but to make useful backend technology
available in environments that modern tooling often overlooks. Loxodontu is built for
open access: practical APIs, authentication, data management, and application backends
that can run where many people already are.
