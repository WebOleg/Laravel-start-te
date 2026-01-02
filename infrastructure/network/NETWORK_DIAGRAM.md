# Tether Network Architecture

## THR-176: Configure private networking
```
                          INTERNET
                              │
                              │ HTTPS (443)
                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        PUBLIC ZONE                                       │
│                                                                          │
│    ┌──────────────────────────────────────────────────────────────┐    │
│    │              LOAD BALANCER (nginx)                            │    │
│    │              Public IP: xxx.xxx.xxx.xxx                       │    │
│    │              Ports: 80, 443 (public)                          │    │
│    └──────────────────────────────────────────────────────────────┘    │
│                              │                                          │
└──────────────────────────────│──────────────────────────────────────────┘
                               │ Private Network (10.0.1.0/24)
                               ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        PRIVATE ZONE                                      │
│                                                                          │
│    ┌─────────────────┐    ┌─────────────────┐                          │
│    │   API Node 1    │    │   API Node 2    │                          │
│    │   10.0.1.11     │    │   10.0.1.12     │                          │
│    │   Port: 9000    │    │   Port: 9000    │                          │
│    └─────────────────┘    └─────────────────┘                          │
│             │                      │                                    │
│             └──────────┬───────────┘                                    │
│                        │                                                │
│    ┌─────────────────┐ │ ┌─────────────────┐                           │
│    │  Worker Node    │ │ │     Redis       │                           │
│    │   10.0.1.21     │ │ │   10.0.1.31     │                           │
│    │   (Horizon)     │ │ │   Port: 6379    │                           │
│    └─────────────────┘ │ └─────────────────┘                           │
│                        │                                                │
│    ┌─────────────────┐ │ ┌─────────────────┐                           │
│    │   PostgreSQL    │ │ │     MinIO       │                           │
│    │   10.0.1.41     │◄┘ │   10.0.1.51     │                           │
│    │   Port: 5432    │   │   Port: 9000    │                           │
│    │   pgBouncer:6432│   └─────────────────┘                           │
│    └─────────────────┘                                                  │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## IP Allocation

| Service | Private IP | Ports | Public Access |
|---------|------------|-------|---------------|
| Load Balancer | 10.0.1.10 | 80, 443 | ✅ Yes |
| API Node 1 | 10.0.1.11 | 9000 | ❌ No |
| API Node 2 | 10.0.1.12 | 9000 | ❌ No |
| Worker Node | 10.0.1.21 | - | ❌ No |
| Redis | 10.0.1.31 | 6379 | ❌ No |
| PostgreSQL | 10.0.1.41 | 5432, 6432 | ❌ No |
| MinIO | 10.0.1.51 | 9000, 9001 | ❌ No |

## Security Rules

### Load Balancer (Public)
- ALLOW inbound 80/tcp from 0.0.0.0/0 (HTTP redirect)
- ALLOW inbound 443/tcp from 0.0.0.0/0 (HTTPS)
- ALLOW outbound to 10.0.1.0/24 (private network)

### API/Worker Nodes (Private)
- DENY inbound from 0.0.0.0/0
- ALLOW inbound from 10.0.1.10 (load balancer)
- ALLOW outbound to 10.0.1.0/24 (internal services)

### Data Services (Private)
- DENY inbound from 0.0.0.0/0
- ALLOW inbound from 10.0.1.0/24 (internal only)
