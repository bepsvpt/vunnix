# Module Boundaries

This repository uses a capability-oriented modular monolith structure.

## Canonical Modules

- `Chat`
- `WebhookIntake`
- `TaskOrchestration`
- `GitLabIntegration`
- `ReviewExecution`
- `FeatureExecution`
- `Observability`
- `AdminGovernance`
- `Shared`

## Layering Inside a Module

Each module should evolve toward this layout:

- `Domain/` — invariants, value objects, domain services
- `Application/` — use cases, commands/queries, contracts
- `Infrastructure/` — framework adapters (Eloquent, HTTP, queue, broadcast)
- `Api/` — controllers, requests, resources

## Boundary Rules

- Cross-module calls must go through contracts defined in `Application/Contracts`.
- Do not call another module's `Infrastructure/` directly.
- Shared low-level utilities live in `Shared`.
- New features should be implemented in module-owned paths first, then legacy code can be adapted.

## Initial Ownership Map

| Module | Primary Legacy Surface |
|---|---|
| `WebhookIntake` | `app/Http/Controllers/WebhookController.php`, `app/Services/EventRouter.php`, webhook DTO/events |
| `TaskOrchestration` | `app/Services/TaskDispatchService.php`, `app/Services/TaskDispatcher.php`, `app/Jobs/ProcessTask.php`, `app/Jobs/ProcessTaskResult.php` |
| `GitLabIntegration` | `app/Services/GitLabClient.php`, related GitLab API wrappers |
| `Chat` | `app/Services/ConversationService.php`, `app/Agents/VunnixAgent.php`, chat API controllers |
| `ReviewExecution` | Result formatting/posting services and review-specific jobs |
| `FeatureExecution` | Feature/UI task result handling paths |
| `Observability` | `AlertEventService`, metrics aggregation/query services, dashboard data services |
| `AdminGovernance` | admin API controllers, policy/RBAC-adjacent orchestration |
| `Shared` | queue names, common envelopes, reusable contracts and helpers |

## Migration Note

Legacy paths remain active while module paths are introduced with compatibility adapters.
