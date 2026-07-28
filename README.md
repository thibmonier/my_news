# Briefly AI — Backend

**Daily Brief IA — 3 histoires majeures synthétisées, fort signal, faible bruit.**

Backend Symfony 8 + API Platform 4, architecture hexagonale/DDD, servi par FrankenPHP en mode worker.

---

## Prérequis

| Outil | Version minimale |
|-------|-----------------|
| Docker | 24+ |
| Docker Compose | 2.20+ |
| GNU Make | 4.x |

Aucune installation PHP/Composer locale requise — tout s'exécute dans les conteneurs.

---

## Quickstart

```bash
# 1. Copier la configuration d'environnement
cp env.example .env          # adapter .env.local si besoin

# 2. Construire les images Docker
make build

# 3. Démarrer les services (PostgreSQL + Redis + App)
make up

# 4. Installer les dépendances Composer
make install

# 5. Lancer les migrations (Sprint 1+)
make migrate

# 6. Vérifier que la stack fonctionne
curl http://localhost/api/health   # → {"status":"ok","components":[...],...}

# 7. Suite de tests
make test
```

---

## Stack technique

| Couche | Technologie | Ref |
|--------|-------------|-----|
| Serveur | FrankenPHP 1 (worker mode, PHP 8.5 Alpine) | constitution §3 |
| Framework | Symfony 8 | constitution §3 |
| API | API Platform 4 (REST/JSON-LD, /api/v1/...) | ADR-007 |
| Base de données | PostgreSQL 16 | ADR-006 |
| Cache / Queue | Redis 7 (Streams, AOF) | ADR-006 |
| Conteneurs | Docker Compose | constitution §3 |

---

## Architecture hexagonale

```
src/
├── Domain/          # Coeur métier — PHP pur, 0 dépendance framework
│   └── Health/      # VO: HealthReport, ComponentStatus | Port: HealthProbeInterface
├── Application/     # Use cases CQRS léger — dépend uniquement du Domain
│   └── Health/      # GetHealthQuery + GetHealthHandler
├── Infrastructure/  # Adapters — Doctrine, Redis, Mistral, Stripe...
│   └── Health/      # DatabaseHealthProbe, RedisHealthProbe
└── Presentation/    # Controllers Symfony, API Platform Resources
    └── Controller/  # HealthController (GET /api/health)
```

**Règles deptrac (CI bloquant) :**
- `Domain: []` — aucune dépendance
- `Application: [Domain]`
- `Infrastructure: [Domain, Application]`
- `Presentation: [Domain, Application]`

---

## Commandes Make

| Commande | Description |
|----------|-------------|
| `make up` | Démarrer tous les services |
| `make down` | Arrêter tous les services |
| `make build` | (Re)construire les images Docker |
| `make install` | Installer les dépendances Composer |
| `make test` | Exécuter la suite Pest |
| `make phpstan` | Analyse statique PHPStan (niveau max) |
| `make deptrac` | Vérification des couches hexagonales |
| `make cs` | Vérification code style (dry-run) |
| `make cs-fix` | Correction automatique du code style |
| `make migrate` | Exécuter les migrations Doctrine |
| `make sh` | Shell dans le container app |
| `make quality` | Tous les contrôles qualité |

---

## Endpoints

### Sprint 0 — Health

| Méthode | Route | Description | Auth |
|---------|-------|-------------|------|
| GET | `/api/health` | Statut de la stack (PostgreSQL + Redis) | Non |

**Réponse 200 (stack ok) :**
```json
{
  "status": "ok",
  "components": [
    {"name": "database", "status": "ok", "message": "PostgreSQL connected"},
    {"name": "redis", "status": "ok", "message": "Redis connected"}
  ],
  "timestamp": "2026-07-28T10:00:00+00:00"
}
```

**Réponse 503 (composant dégradé) :**
```json
{
  "status": "degraded",
  "components": [
    {"name": "database", "status": "ok", "message": "PostgreSQL connected"},
    {"name": "redis", "status": "degraded", "message": "Redis unreachable: ..."}
  ],
  "timestamp": "2026-07-28T10:00:00+00:00"
}
```

### Sprint 1+ — API Platform

Documentation OpenAPI interactive : `http://localhost/api/docs`

---

## Qualité

| Outil | Niveau | CI |
|-------|--------|----|
| PHPStan | max + strict-rules | Bloquant |
| PHP-CS-Fixer | PSR-12 + @Symfony | Bloquant |
| Deptrac | Hexagonal layers | Bloquant |
| Pest | Unit + Feature | Bloquant |
| composer audit | CVE scan | Bloquant |
| Hadolint | Dockerfile lint | Warning |

---

## Structure du projet

```
/
├── .github/workflows/    # CI GitHub Actions
├── config/               # Configuration Symfony
├── docker/               # Configuration Docker (php.ini)
├── docs/                 # ADRs, OpenAPI, architecture
├── migrations/           # Migrations Doctrine
├── project-management/   # Constitution, tech-spec, backlog
├── public/               # Point d'entrée HTTP (index.php)
├── src/                  # Code source (hexagonal/DDD)
├── tests/                # Tests Pest (Unit + Feature)
└── translations/         # Traductions i18n (EN/FR)
```

---

## Documentation

| Document | Emplacement |
|----------|-------------|
| Constitution (immuable) | `project-management/constitution.md` |
| Spec technique | `project-management/tech-spec.md` |
| ADRs | `docs/adr/` |
| Backlog | `project-management/backlog/` |

---

*Briefly AI — fort signal, faible bruit, ton éditorial.*
