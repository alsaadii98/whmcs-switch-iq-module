<img src="./switchmodule/logo.png" alt="Switch Logo" style="width:200px;"/>

# Switch Payment Gateway for WHMCS

## Overview

The **Switch Payment Gateway Module** seamlessly integrates Switch with WHMCS, allowing customers to make payments directly using their Debit/Credit cards

---

## Installation Guide

1. Navigate to your WHMCS directory.
2. Move the following files and directories to `modules/gateways`:
   - `includes/`
   - `switchmodule/`
   - `switchmodule.php`
3. Move `callback/switchmodule.php` to the `callback` directory in WHMCS.
4. Open the WHMCS Marketplace, search for "Switch," and install the module.
5. Go to WHMCS settings under "Payment Gateways" and enter your Switch credentials.

---

## Project Structure

```
├── callback
│   └── switchmodule.php
├── switchmodule
│   ├── logo.png
│   └── whmcs.json
├── switchmodule.php
└── README.md
```

---

For any issues, feel free to reach out.

Developed with ❤️ by [@alsaadii98](https://github.com/alsaadii98) at [eSITE Information Technology](https://esite-iq.com).
