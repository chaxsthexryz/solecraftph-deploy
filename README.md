# SoleCraftPH

A footwear e-commerce site (PHP + MySQL) with an admin backend and a mobile API.

> **School project.** SoleCraftPH is a student project made for coursework. It is not a real store: no real products are sold and no real payments are taken (PayMongo runs in test mode).

## Copyright and trademarks

- The SoleCraftPH source code is © 2026 the SoleCraftPH project team. All rights reserved.
- All brand names, logos and product images shown (for example Nike, Adidas, Under Armour, La Sportiva and others) are trademarks or copyrighted works of their respective owners. They appear only to demonstrate the app for education. There is no affiliation with, or endorsement by, any brand.
- If you are a rights holder and want something removed, open an issue or use the contact on the site and it will be taken down.

## Running it

Docker image based on `php:8.3-apache`. Set these environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Mail, PayMongo and push-notification keys are supplied as files (`mail.php`, `paymongo_keys.php`, `fcm.php`) in `/etc/secrets/` and are never committed.
