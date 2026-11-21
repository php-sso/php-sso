# OpenID conformance suite

Runs the official [OpenID conformance suite](https://gitlab.com/openid/conformance-suite) against a throwaway copy of
php-sso, so protocol bugs show up before an application finds them. Nothing here touches your real database: the test
instance gets its own empty `var/` under `conformance/.state/`.

## What it needs

- Docker (the suite, MongoDB, a PHP container for php-sso, a Python container for the runner).
- The suite checked out next to this repository, at `../../conformance-suite` relative to this folder:

  ```bash
  git clone --depth 1 --branch release-v5.3.4 https://gitlab.com/openid/conformance-suite.git ../conformance-suite
  ```

  `run.sh` uses that release's prebuilt images, so nothing is compiled.

## Running it

```bash
conformance/run.sh up      # start the suite and a test php-sso, create the test person and the two clients
conformance/run.sh run     # run the Basic OP plan (about five minutes); results go to conformance/.state/results
conformance/run.sh down    # remove everything
```

While it is up, the suite's own UI is at <https://localhost.emobix.co.uk:8443/> (accept the self-signed certificate).
Each module in the output links to its log there; the plan page shows pass, warning, review and failed per module.

## How the test instance differs from a real one

- **PKCE is optional for confidential clients** (`SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS=1`). The suite's Basic OP
  tests do not send PKCE, which OpenID Connect allows. Public clients still need it. A production instance should keep
  the default, which requires PKCE of everyone.
- The issuer is `http://sso:9000`, reachable only from the Docker network. The suite drives the sign-in pages itself
  (see `plan-config.json`), so no browser is needed on your machine.
- The test person (`conformance` / `conformance-pass`) and the clients (`conformance-1`, `conformance-2`) are
  well-known values created by `setup.php`. Never use them anywhere else.

## Reading the results

| Result | Meaning |
|---|---|
| PASSED | The behaviour is what the specification asks for. |
| WARNING | Something the specification only recommends, or that the suite could not fully check. |
| SKIPPED | The server does not claim to support what the test needs (for example the `address` scope). |
| REVIEW | Passed its checks and has a screenshot (of an error or sign-in page) that a person is meant to look at. |
| FAILED | The server broke a rule. These are the ones to fix. |

`plan-config.json` has an `override` entry for every test that needs a screenshot: the suite fills the placeholder when
the page contains the given text, which for the redirect-URI tests is part of the server's error response.

## Not covered yet

Only the **Basic OP** plan has been run. The other certification plans (Config, Implicit, Hybrid, Form Post, Dynamic,
RP-initiated and back-channel logout) are not, and several do not apply: implicit and hybrid flows and dynamic client
registration are deliberately not implemented.
