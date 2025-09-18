# Menu Active Trail Correct

A lightweight Drupal 11 module that fixes/adjusts active trail calculation for menu links in specific cases.

## Installation
- Place this folder in `modules/custom/menu_active_trail_correct`.
- Enable the module: `drush en menu_active_trail_correct`.

## What it does (future)
- Provide a service decorator for `menu.active_trail` to alter the active link detection.
- Optional hooks to tweak trail based on route parameters.

## Development notes
- Add a `menu_active_trail_correct.services.yml` to decorate `menu.active_trail` if needed.
- Add tests under `tests/src/Kernel` when behavior is implemented.
