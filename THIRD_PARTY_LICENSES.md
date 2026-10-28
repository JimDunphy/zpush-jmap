# Third-Party Licensing Notes

This file summarizes notable third-party code and dependencies used by this repository.

## Repository License

- This repository is licensed under MIT (`LICENSE`).

## Included Third-Party Source Files

The following files include upstream third-party source and retain their upstream copyright/license notices:

- `backend/stalwart/mime.php`
  Source lineage: PEAR `Mail_Mime`
  License notice in file: BSD-style
- `backend/stalwart/mimePart.php`
  Source lineage: PEAR `Mail_Mime` / `Mail_mimePart`
  License notice in file: BSD-style

Refer to the headers in each file for exact copyright and license terms.

## Upstream Components Pulled During Build/Runtime

- Z-Push framework is fetched during image build (`nginx/Dockerfile`) from upstream releases.
  The image applies `backend/stalwart/zpush-compat.patch` to reject empty
  folder mappings, ignore invalid entries when routing notifications, and encode
  a completed task's epoch fallback as a non-empty completion date. This
  modification to the Z-Push framework is covered by its upstream AGPL license.
- Stalwart server is an external service this project integrates with.
- Rust crates and PHP/OS packages are pulled from their respective package registries during build.

Each upstream component remains under its own license terms. You are responsible for complying with those terms when redistributing binaries/images or operating this software in your environment.
