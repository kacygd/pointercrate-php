# API

Docs:

- `GET /api/docs`
- `GET /api/docs.php`

Endpoints:

- `GET /api/v2/demons/listed/?name=Level%20Name`
- `GET /api/v2/demons/listed/?level_id=12345`
- `GET /api/v2/demons/listed/?tag=Collab`
- `GET /api/listed/?level_id=12345`
- `GET /api/levels/12345`
- `GET /api/v2/demons/`
- `GET /api/v2/demons/{id}/`
- `GET /api/v1/records/`
- `GET /api/v1/players/`
- `GET /api/v1/players/ranking/`
- `GET /api/v1/list_information/`

## Tags

Levels can carry custom tags (created by list editors in the admin panel).

- Every demon payload contains a `tags` array: `[{"id": 1, "name": "Collab", "color": "#e74c3c"}]`
- Filter demons by tag with `?tag=Collab` (exact tag name, case-insensitive) on any demon listing endpoint.