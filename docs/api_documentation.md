# Indiescovery API

All endpoints use the session cookie set when you sign in with Steam. Responses are JSON.

## Authentication

Sign in by sending the browser to `GET /auth/steam`. After signing in on Steam, the browser returns
to the Indiescovery site at `/profile`. If the sign-in is cancelled or cannot be verified, it
returns to `/?login=failed`.

Requests without a valid session receive:

```http
HTTP/1.1 401 Unauthorized

{"error": "unauthenticated"}
```

## GET /api/me

Returns the signed-in player and their Steam library.

```json
{
  "player": {
    "steamId": "76561197960287930",
    "name": "Robin",
    "avatarUrl": "https://avatars.steamstatic.com/....jpg",
    "profileUrl": "https://steamcommunity.com/id/robin/",
    "steamCreatedAt": "2010-05-01T00:00:00.000000Z"
  },
  "library": {
    "private": false,
    "syncedAt": "2026-09-18T10:00:00.000000Z",
    "gameCount": 2,
    "totalMinutes": 5520,
    "recentMinutes": 30,
    "games": [
      {
        "appId": 367520,
        "name": "Hollow Knight",
        "playtimeMinutes": 5400,
        "recentMinutes": 30,
        "capsuleUrl": "https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/367520/header.jpg",
        "tags": ["Metroidvania", "Souls-like"],
        "reviewCount": 415946,
        "positiveRatio": 0.97
      }
    ]
  }
}
```

| Field | Notes |
|---|---|
| `player.avatarUrl`, `player.profileUrl`, `player.steamCreatedAt` | May be `null` if Steam does not provide them. |
| `library.private` | `true` when the player's Steam game details are not public. The game list then shows the last library we could read, possibly none. |
| `library.syncedAt` | When the library was last read from Steam, or `null` if never. |
| `library.recentMinutes`, `games[].recentMinutes` | Playtime in the last two weeks. |
| `games` | Sorted by `playtimeMinutes`, descending. |
| `games[].tags`, `reviewCount`, `positiveRatio` | `null` until we have store data for that game. `positiveRatio` is between 0 and 1. |

Timestamps are ISO 8601 in UTC.

## POST /api/logout

Ends the session. Returns `204 No Content`.

This request must carry the CSRF token: send the value of the `XSRF-TOKEN` cookie, URL-decoded, in
the `X-XSRF-TOKEN` header. A missing or wrong token returns `419`.

## Errors

Unknown endpoints return `404` with a JSON body `{"message": "..."}`.
