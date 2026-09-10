# Localizing the HERD Database

**Organizations:** University of San Diego, Elon University, Pepperdine University

Please contact Jae Kim (jaedkim@sandiego.edu) for any general questions.

**Project Funded by:** NSF

_This material is based upon work supported by the National Science Foundation under Grant No. 2528426. Any opinions, findings, and conclusions or recommendations expressed in this material are those of the author(s) and do not necessarily reflect the views of the National Science Foundation._

<!-- Percentage of container width -->
<img src="Assets/NSF_logo.png" width="15%">

---

> **This is a fork.** This repository is maintained by Kennesaw State University's Office of Research (KSU OoR) and is forked from [jaedkim23/granted](https://github.com/jaedkim23/granted), created by Jae Kim and collaborators at the University of San Diego, Elon University, and Pepperdine University under NSF Grant No. 2528426. All credit for the original HERD localization design goes to the upstream authors — this fork adds a [Vercel deployment path](#method-3---deploy-on-vercel-this-fork) (see below) for KSU OoR's own use.

---

## 📖 Overview

This is a repository for the GRANTED project specifically for working with the NSF HERD data. The figure below shows the main steps of the project. The main scripts create the backend database structure and maps the relevant fields to the correct tables in the database. Any application can connect to the database. There is an example Tableau application provided in the repo. 

<img src="Assets/Process Overview.png" width="95%">

There are two main ways to replicate the project for your own organzation. 

---

## Method 1 - Use Existing Web Infrastructure

The first method involves integrating the application directly into your organization's existing web infrastructure. For example, the University of San Diego (USD) created a Tableau application that directly displays on its webpage (see below).

<img src="Assets/option1.png" width="50%">

In this case, it's most likely that your organization's IT department would have to provide support in integration your application directly into the existing websites. Here is a step-by-step guide to creating your own HERD reporting application.

* **Step 1: Create the Database Schema**

Use the file "create_herd_tbl_maria.sql" to create the database schema. The ER diagram of the database schema is shown below.

<img src="Assets/ER_Diagram.png" width="85%">

This database will organize information from the following tables from the NSF HERD website (as of 2024 release).

1) Table 13 - Higher education R&D expenditures at institutions in the standard form survey population, ranked by FY 2024 R&D expenditures: FYs 2010–24
2) Table 14 - Higher education R&D expenditures at institutions in the standard form survey population, ranked by all R&D expenditures, by source of funds: FY 2024
3) Table 15 - Higher education R&D expenditures at institutions in the standard form survey population, ranked by all R&D expenditures, by R&D field: FY 2024
4) Table 27 - Headcount and FTEs of R&D personnel at higher education institutions in the standard form survey population, by state, institutional control, institution, and personnel function: FY 2024

Remember to create an environment file that stores all relevant credentials like the server address, port number, username, and password. 

We now add the corresponding records into the relevant tables created in step 1.

* **Step 2: Extract, Transform and Load Data**

Use the files "HERD_download_maria_aws.py" and "HERD_functions.py" to map the data from the NSF HERD website into the database created in step 1. Both files are Python scripts and automatically maps the correct data fields into the correct fields/tables. You only have to run the "HERD_download_maria_aws.py" file to add the records into the tables (this file uses the functions in "HERD_functions.py"). 

Note: The Python scripts require access to the local environment (.env) file with credentials to the database created in step 1. 

Once the "HERD_download_maria_aws.py" script terminates, the relevant data from the 4 tables from NSF HERD are now available for your use. You can create your own application using the database or use the Tableau example in the next step.

* **Step 3: Data Visualization (Tableau)**

You can use the following PDF file for a step-by-step instructions on how to connect your DB to Tableau to create your own application.

  [MariaDB Tableau Integration](<Assets/MariaDB Tableau Integration.pdf>)

* **Step 4: Integrate into Existing Webpages**

You can insert your Tableau application as an iframe element directly into your existing webpages. It is strongly recommended to consult your organization's IT or web team for this step.



## Method 2 - "Turnkey" 

The second method is similar to Method 1 but it is for organizations without existing web resources for direct integration. In this method, there is no chanage in step 1, 2, and 3. The only difference is in step 4 since there is no existing webpages to integrate the Tableau applications. 

![Process_Steps](<Assets/Process_Steps.png>)

Refer to Steps 1, 2, and 3 prescribed in Method 1. 

* **Step 4: Create Web Resources**

Please refer to the instructions provided in the [README](<turnkey/README.md>) in the **turnkey** directory. The instructions provide guidance on how to use the webpage templates for your own use. 

## Method 3 - Deploy on Vercel (this fork)

This fork adds a container-based deployment path for the `turnkey` PHP app so it can run on [Vercel](https://vercel.com) (Vercel has no native PHP runtime, so this uses [FrankenPHP](https://frankenphp.dev/) via Vercel's [container runtime](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker)). Relevant files:

* `Dockerfile.vercel` — builds `turnkey/includes` (via Composer) and `turnkey/html/nsfproject` into a FrankenPHP image.
* `Caddyfile` — serves the app from `/app/public/nsfproject`.
* `vercel.json` — tells Vercel to build and route to the container.
* `docker/generate-config.php` / `docker/entrypoint.sh` — regenerate the app's `conf.ini`/`settings.php` from environment variables on every container boot. The turnkey app normally writes these via its interactive setup wizard, but Vercel's container filesystem doesn't persist across deploys or cold starts, so configuration is env-var driven instead.

### Required environment variables

| Variable | Description |
|---|---|
| `DB_HOST` | Database host. Use a managed MySQL/MariaDB or Postgres instance (e.g. a Vercel Postgres/Neon/PlanetScale integration) — Vercel's container filesystem can't host the database itself. |
| `DB_PORT` | Database port (e.g. `3306` for MySQL/MariaDB, `5432` for Postgres). |
| `DB_NAME` | Database name. |
| `DB_USER` | Database user. |
| `DB_PASSWORD` | Database password. |

### Optional environment variables

| Variable | Description |
|---|---|
| `APP_SITE_TITLE`, `APP_SCHOOL_NAME`, `APP_LOGO_URL` | Header branding. |
| `APP_EMAIL_SENDER`, `APP_COPYRIGHT` | Footer settings. |
| `APP_RESOURCE_LINKS` | Comma-separated Markdown links, e.g. `[KSU OoR](https://oor.kennesaw.edu/)`. |
| `APP_CSS_OVERRIDE` | Web path to a custom CSS override file. |
| `REDIS_URL` | If set, PHP sessions are stored in Redis instead of the container's local (ephemeral) disk. Recommended once this sees real traffic, since Vercel Functions can scale across multiple instances. |

### Deploying

1. Create the database (see `create_herd_tbl_maria.sql` or `turnkey/includes/nsfproject/conf/tables_postgres.sql`) and set the environment variables above in the Vercel project settings.
2. `vercel deploy --prod` (or connect the GitHub repo in the Vercel dashboard for git-based deploys).
3. Log in with the seeded admin account from `turnkey/README.md`, then immediately create a new admin user and remove/rotate the default one.

This path hasn't been exercised against a live Vercel + database deployment yet — validate it end-to-end before relying on it for anything production-facing.
