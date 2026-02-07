# Infrastructure Documentation


## 1. Infrastructure Diagram
**Visual Overview of System Architecture & Data Flow**

```
┌─────────────────────────────────────────────────────────────────────────┐
│                                  USER                                   │
│                         (HTTP/HTTPS Requests)                           │
└─────────────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────────────────────────────────────────────────────────┐
│                     ROUTING & FRONTEND LAYER                            │
│                                                                         │
│   ┌─────────────────────────┐        ┌──────────────────────────────┐   │
│   │   Load Balancer (LB)    │───────►│      Frontend (NextJs)       │   │
│   │   (Traffic Entry)       │        │      (User Interface)        │   │
│   └────────────┬────────────┘        └──────────────┬───────────────┘   │
│                │                                    │                   │
└────────────────┼────────────────────────────────────┼───────────────────┘
│                                    │
▼                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER (Backend)                          │
│                                                                         │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │                  API Node (API #1 & #2)                         │   │
│   │              (Core Logic & Request Handling)                    │   │
│   └──────┬───────────────────────┬──────────────────────────────────┘   │
│          │                       │                                      │
│          │ (Job Queue)           ▼                                      │
│          │              ┌───────────────────────────────────────────┐   │
│          └─ - - - - - ► │               Worker Node                 │   │
│                         │    (Validation, Payments, Webhooks)       │   │
│                         └────────────────────┬──────────────────────┘   │
│                                              │                          │
└──────────────────────────────────────────────┼──────────────────────────┘
│                                     │                        ^
│                                     │                        │
▼                                     ▼                        ▼
┌────────────────────────────────────────────────────────┐     ┌──────────────┐
│                       DATA LAYER                       │     │ EXTERNAL APIs│
│                                                        │     │ / GATEWAYS   │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │     │              │
│   │    Redis    │  │  PgBouncer  │  │     S3      │    │     │ - Banks      │
│   │   (Cache)   │  │   (Pooler)  │  │  (Storage)  │    │◄───►│ - Providers  │
│   │   (Queue)   │  │             │  │             │    │     │              │
│   └─────────────┘  └──────┬──────┘  └─────────────┘    │     └──────────────┘
│                           │                            │
│                    ┌──────▼──────┐                     │
│                    │  Postgres   │                     │
│                    │ (Database)  │                     │
│                    └─────────────┘                     │
└────────────────────────────────────────────────────────┘
```
---

## 2. Server Inventory & Access

### **Layer 1: Routing & Frontend**
**Role:** Entry point for all incoming HTTP/HTTPS traffic, SSL termination, and hosting of the Next.js user interface.

| Role | Server Name | Public IP / Access | Specs / OS | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Load Balancer** | `DO-nyc1-1vcpu-1gb` | `192.241.155.116` | DO s-1vcpu-1gb<br>Ubuntu 24.04 (LTS) | **Nginx Reverse Proxy.** Manages SSL (Certbot), routing, and load balancing. |
| **Frontend** | `DO-nyc1-2vcpu-2gb` | `137.184.49.0`<br>*(Internal: 10.136.2.20)* | DO s-1vcpu-1gb<br>Ubuntu 24.04 (LTS) | **Next.js Container.** Handles Server-Side Rendering (SSR) and serves the UI. |

---

### **1. Load Balancer Configuration**
* **Host:** `DO-nyc1-1vcpu-1gb`
* **Domain:** `testingiscool.online`
* **Config Path:** `/etc/nginx/sites-available/tether`
* **SSL:** Managed via Let's Encrypt (Certbot).
* **Health Check:** `/lb-health` on Port 80 (Returns `200 ok`, bypasses HTTPS redirect).

#### **Routing Logic**
The Nginx configuration handles three specific traffic flows:

1.  **Frontend (`/`)**:
    * **Upstream:** `tether_web` -> `10.136.2.20:3000` (Private IP).
    * **Features:** WebSocket support enabled (Upgrade headers).

2.  **General API (`/api/`)**:
    * **Upstream:** `tether_api` -> Load balances between `10.136.2.13:8000` and `10.136.1.238:8000`.
    * **Headers:** Enforces `X-Forwarded-Proto https` and passes real client IPs.

3.  **Webhooks Exception (`/api/webhooks/`)**:
    * **Target:** Direct proxy to `http://159.223.98.71:8000`.
    * **Note:** Bypasses the standard API load balancer upstream group.

---

### **2. Frontend Configuration**
* **Host:** `DO-nyc1-2vcpu-2gb`
* **Project Path:** `/opt/tether-next`
* **Docker Compose Path:** `/opt/tether-next/infrastructure/docker/NextJs/docker-compose.yml`

#### **Deployment Details**
The application runs as a Docker container mapped to host port **3000**.
* **Environment:** Production (`NODE_ENV=production`).
* **Build Args:** Injects `NEXT_PUBLIC_API_URL` during build.
* **Networking:** The Load Balancer connects via the private VPC IP (`10.136.2.20`).

#### **Docker Compose Snippet**
```yaml
services:
  next-app:
    build:
      context: ../../../
      dockerfile: infrastructure/docker/NextJs/Dockerfile
      args:
        - NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
    env_file:
      - ../../../.env
    ports:
      - "3000:3000"
    environment:
      - NODE_ENV=production
```

### **Layer 2: Application (Backend)**
**Role:** Core business logic, API processing, and background task execution via Laravel.

| Role | Server Name | Public IP / Access | Specs / OS | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Backend API** | `DO-nyc1-2vcpu-4gb` (Api #1) | `206.189.238.18` | DO s-2vcpu-4gb<br>Ubuntu 24.04 (LTS) | **Primary Node.** Handles main API traffic. runs Migrations. |
| **Backend API** | `DO-nyc1-2vcpu-4gb` (Api #2) | `192.81.216.202` | DO s-2vcpu-4gb<br>Ubuntu 24.04 (LTS) | **Secondary Node.** Scale-out node for high traffic loads. |
| **Task Runner** | `DO-nyc1-2vcpu-4gb` (Worker #1) | `159.223.98.71` | DO s-2vcpu-4gb<br>Ubuntu 24.04 (LTS) | **Job Processor.** Dedicated node for Horizon/Queues (Billing, VOP). |

---

### **1. API Nodes Configuration**
* **Hosts:** API #1 & API #2
* **Project Path:** `/opt/tether-laravel/`
* **Docker Compose Path:** `/opt/tether-laravel/infrastructure/docker/api-node/docker-compose.yml`

#### **Architecture**
The API nodes run an Nginx container serving a PHP-FPM (Laravel) container.
* **Port Mapping:** Host `8000` -> Container `80`.
* **Networking:** Private networking via `tether_network` bridge.
* **Storage:** Binds local project directory and storage to `/var/www`.

#### **Docker Compose Snippet (API)**
```yaml
services:
  app:
    container_name: tether_app
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ../../../:/var/www
      - ../../../storage:/var/www/storage
    # ... env_file and networks

  nginx:
    image: nginx:alpine
    container_name: tether_nginx
    ports:
      - "8000:80"
    depends_on:
      - app
```

### **2. Worker Node Configuration**

* **Host:** Worker #1
* **Project Path:** `/opt/tether-laravel/`
* **Docker Compose Path:** `/opt/tether-laravel/infrastructure/docker/worker/docker-compose.yml`

### Architecture
The Worker node includes a specialized Horizon container to process queued jobs.

* **Service:** `tether_horizon`
* **Command:** `php artisan horizon`
* **Webhooks:** Also exposes Nginx on port 8000 (specifically for handling webhooks that route directly to this IP).

### Docker Compose Snippet (Worker)

```yaml
services:
  app:
    container_name: tether_app
    # ... Standard app config

  horizon:
    container_name: tether_horizon
    command: php artisan horizon
    depends_on:
      - app
    # ... mounts and network

  nginx:
    container_name: tether_nginx
    ports:
      - "8000:80"
```


## Monitoring & Logs

To view the real-time processing logs for the queue worker:

```bash
cd /opt/tether-laravel/
docker compose -f infrastructure/docker/worker/docker-compose.yml logs -f horizon
```


# 3. Deployment Strategy (CI/CD)

**Workflow File:** `/opt/tether-laravel/.github/workflows/deploy-backend.yml`

The deployment is automated via GitHub Actions with the following sequence to ensure zero downtime and data consistency:

* **Node 1 (Primary):** Pulls code, rebuilds containers, and runs database migrations.
* **Node 2 (Secondary):** Pulls code and rebuilds containers (**No migrations**).
* **Worker:** Pulls code, rebuilds, and terminates Horizon (graceful restart).

---

## Manual Deployment Commands

If CI/CD fails, use these manual commands on the respective servers using the `deploy` user.

### On API Node #1 (Master - Includes Migrations)

```bash
cd /opt/tether-laravel

# 1. Reset Code
sudo -u deploy git fetch origin main
sudo -u deploy git reset --hard origin/main

# 2. Rebuild Containers
docker rm -f tether_app tether_nginx || true
docker compose -f infrastructure/docker/api-node/docker-compose.yml up -d --build --remove-orphans

# 3. Application Setup
docker exec tether_app composer install --no-dev --optimize-autoloader
docker exec tether_app php artisan migrate --force # <--- ONLY ON NODE 1
docker exec tether_app php artisan optimize:clear
docker exec tether_app php artisan config:cache
docker exec tether_app php artisan route:cache
docker exec tether_app php artisan view:cache
```

### On API Node #2 (Replica)

Run the same commands as Node #1, but **SKIP** `php artisan migrate --force`.

---

### On Worker Node

```bash
cd /opt/tether-laravel
sudo -u deploy git fetch origin main
sudo -u deploy git reset --hard origin/main

# Rebuild (Includes Horizon container)
docker rm -f tether_app tether_nginx tether_horizon || true
docker compose -f infrastructure/docker/worker/docker-compose.yml up -d --build --remove-orphans

# Setup & Restart Queue
docker exec tether_app composer install --no-dev --optimize-autoloader
docker exec tether_app php artisan optimize:clear
docker exec tether_app php artisan config:cache
docker exec tether_horizon php artisan horizon:terminate # <--- Graceful restart
```


### **Layer 3: Data & Storage**
**Role:** Persistent storage, caching, and file management.

| Role | Server Name | Public IP / SSH Host | Description |
| :--- | :--- | :--- | :--- |
| **Database** | `Postgres (#1)` | `159.89.226.106` | **Primary DB.** Stores all transactional data. |
| **DB Pooler** | `PgBouncer` | `192.241.149.63` | **Connection Manager.** Intermediary between API/Worker and Postgres. |
| **Cache** | `Redis` | `206.189.235.41` | **In-Memory Store.** Handles caching, sessions, and job queues (Horizon). |
| **Storage** | `DO-nyc1-s-1vcpu-1gb` (MinIO) | `161.35.127.185` | **Object Storage.** Stores uploaded files (CSVs, images). |

---

### **1. Object Storage Configuration (MinIO)**
* **Host:** `DO-nyc1-s-1vcpu-1gb`
* **Specs:** s-1vcpu-1gb / nyc1 / Ubuntu 24.04 (LTS)
* **IP:** `161.35.127.185`

#### **Access & Dashboard**
MinIO provides an S3-compatible API for file storage and a web-based console for management.

* **Console URL:** `http://161.35.127.185:9001/login`
* **Port 9000:** API Access (S3 Protocol).
* **Port 9001:** Web Console (UI).

#### **Usage**
* Used for storing user uploads, generated reports, and static assets not served by the frontend build.
* accessible internally by the API nodes via the private network (if configured) or public IP.

---

### **Layer 3: Data & Storage**
**Role:** Persistent storage, caching, and file management.

| Role | Server Name | Public IP / Access | Specs / OS | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Database** | `DO-nyc1-4vcpu-8gb` (Postgres #1) | `159.89.226.106`<br>*(Internal: 10.136.2.3)* | DO s-4vcpu-8gb<br>Ubuntu 25.04 x64 | **Primary DB.** Stores all transactional data. |
| **DB Pooler** | `DO-nyc1-1vcpu-1gb` (PgBouncer) | `192.241.149.63`<br>*(Internal: 10.136.2.10)* | DO s-1vcpu-1gb<br>Ubuntu 24.04 (LTS) | **Connection Manager.** Intermediary between API/Worker and Postgres. |
| **Cache** | `DO-nyc1-2vcpu-4gb` (Redis) | `206.189.235.41` | DO s-2vcpu-4gb<br>Ubuntu 24.04 (LTS) | **In-Memory Store.** Handles caching, sessions, and job queues. |
| **Storage** | `DO-nyc1-s-1vcpu-1gb` (MinIO) | `161.35.127.185` | DO s-1vcpu-1gb<br>Ubuntu 24.04 (LTS) | **Object Storage.** Stores uploaded files (CSVs, images). |

---

### **1. Database & Pooling Configuration**

#### **Postgres (Primary)**
* **Host:** `DO-nyc1-4vcpu-8gb`
* **Internal IP:** `10.136.2.3`
* **Port:** 5432

#### **PgBouncer (Connection Pooler)**
* **Host:** `DO-nyc1-1vcpu-1gb`
* **Internal IP:** `10.136.2.10`
* **Listening Port:** 6432
* **Role:** The API nodes connect to this server on port 6432. It maintains a persistent pool of connections to the actual Postgres server (`10.136.2.3`), significantly reducing the overhead of establishing new DB connections.

**Key Configuration Settings (`/etc/pgbouncer/pgbouncer.ini`):**

```ini
[databases]
# Mapping virtual DB names to the physical Postgres server
app_db = host=10.136.2.3 port=5432 dbname=app_db
tether_prod = host=10.136.2.3 port=5432 dbname=tether_prod

[pgbouncer]
# Network Settings
listen_addr = 10.136.2.10
listen_port = 6432
auth_type = scram-sha-256
auth_file = /etc/pgbouncer/userlist.txt

# Pooling Strategy
# "session": Server connection is released back to pool after client disconnects.
pool_mode = session
default_pool_size = 50
min_pool_size = 10
reserve_pool_size = 20
reserve_pool_timeout = 5

# Limits
max_client_conn = 2000
server_lifetime = 3600
server_idle_timeout = 60

# Admin
admin_users = pgbouncer_admin
stats_users = pgbouncer_admin

```


### **Layer 4: Environments**
**Role:** Isolated sandbox for testing.

| Role | Server Name | Public IP / SSH Host | Description |
| :--- | :--- | :---| :--- |
| **Testing** | `Staging` | `137.184.105.172` | **Pre-Prod.** Replica environment for testing changes before deployment. |

---

## 3. Detailed Functional Breakdown: Worker Node
**Server:** `Worker (#1)`
**Framework:** Laravel
**Function:** High-volume financial processing engine.

### **A. Data Ingestion**
* **`ProcessUploadJob`**: Ingests large-scale CSV/Excel files from S3.
* **`ProcessUploadChunkJob`**: Splits large files into manageable chunks for import and deduplication.

### **B. Validation & Verification**
* **`ProcessValidationJob`**: Validates the structural integrity of debtor data.
* **`ProcessVopJob`**: Executes **VOP** (Verification of Payee) and **BAV** (Bank Account Verification) checks via external APIs.

### **C. Billing Execution**
* **`ProcessBillingJob`**: Orchestrates mass payment attempts using profile exclusivity logic.
* **Safety Protocols**: Includes **Circuit Breakers** (stops on high failure rates) and **Rate Limiting** to protect gateways.

### **D. Transaction Lifecycle**
* **`ProcessReconciliationJob`**: Polls providers to sync "Pending" transactions to "Approved/Declined".
* **`ProcessEmpWebhookJob`**: Handles async events (Chargebacks, Retrieval Requests) and updates local records/blacklists.

### **E. Synchronization**
* **`EmpRefreshByDateJob`**: External ledger sync to ensure local data matches the payment provider.
* **`GenerateVopReportJob`**: Creates downloadable compliance reports.
