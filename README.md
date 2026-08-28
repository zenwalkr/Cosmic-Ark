# Cosmic Ark

**Alpha Ro Rescue // 2.5D browser game**

Cosmic Ark is a retro-futurist, dependency-free web game inspired by classic arcade rescue missions. Defend the Ark through a meteor shower, then pilot the shuttle to rescue two creatures from the planet below. Play alone or assemble a real-time crew with a room code.

The game runs entirely in the browser with HTML, CSS, Canvas 2D rendering, the Web Audio API, and five small Wavefront OBJ models. Multiplayer uses a small PHP endpoint and a locked JSON file; there is no database, build step, package manager, or JavaScript framework.

## Features

- Solo missions with Regular, Advanced, and Meteor Shower variations.
- Two-phase gameplay:
  1. Destroy incoming meteors from the four cardinal lanes.
  2. Fly the shuttle, tractor-beam the creatures aboard, and return to the Ark.
- Online rooms for 2–4 players with public or private room codes.
- Crew roles that divide guns, shuttle helm, tractor beam, shield, and decoy duties.
- Lobby chat, host-controlled launch, shared mission state, and automatic player/room cleanup.
- Keyboard controls, touch joystick controls, responsive landscape presentation, CRT scanlines, synthesized audio, and OBJ/MTL asset loading.
- A JavaScript smoke test covering phone sizing, mission initialization, meteor lanes, rescue initialization, rendering, and animation scheduling.
- OBJ fallback geometry so the game can still render if the model files fail to load.

## Requirements

### Solo mode

Any current desktop or mobile browser with Canvas 2D support can run solo mode. Serve the files over HTTP; opening `index.html` directly with `file://` may prevent asset loading in some browsers.

### Online mode

Online play requires:

- PHP 8.1 or newer. The backend uses typed properties/signatures, `mixed`, `never`, `str_starts_with()`, `random_int()`, sessions, and file locking.
- A web server that executes `cosmicark.php` and serves the other files from the same directory.
- PHP session support and write permission for this directory, because the backend creates `.cosmicark_rooms.store.php` at runtime.
- HTTPS in production. The session cookie is marked `Secure` automatically when the request uses HTTPS.

Apache with PHP, nginx with PHP-FPM, or PHP's built-in development server are suitable for local testing.

## Run locally

From the project directory:

```bash
php -S 127.0.0.1:8080
```

Open [http://127.0.0.1:8080/](http://127.0.0.1:8080/) in a browser.

Solo mode needs no backend configuration. To try online mode, open the same URL in two or more browser windows or devices, choose **Online Crew**, create a room in one window, and join with the six-character code in the others. The host must have at least two players before launching.

When deploying under a subdirectory, keep all project files together. `index.html` uses relative paths for `cosmicark.php`, the MTL file, and all OBJ files.

## How to play

### Controls

On desktop:

- `W` / `Arrow Up`: move or fire up
- `S` / `Arrow Down`: move or fire down
- `A` / `Arrow Left`: move or fire left
- `D` / `Arrow Right`: move or fire right
- `Space` or either `Shift` key: use the current special action
- `Esc`: pause solo play or open the mission menu

On touch devices, use the on-screen joystick. Its dominant axis selects one of the four cardinal directions. Use the action button for the current station's special action.

### Meteor shower

Destroy meteors before they hit the Ark. In solo mode you can fire all four lanes and use the shield. In an online crew, stations are assigned by player order: the first players operate the shared gun sectors, while the fourth station operates the shield. A shot costs one fuel unit; destroyed meteors restore one fuel unit, while an Ark impact costs ten.

### Shuttle rescue

Fly the shuttle toward the creatures, hold the tractor beam when close enough to bring one aboard, then return to the Ark to dock and secure the rescue. In solo mode, the action button operates the tractor beam. In an online crew, the second player operates the tractor beam, the first player flies the shuttle, and additional players can deploy decoys.

The mission starts with 40 fuel units. A successful creature rescue restores 10 fuel units. The rescue phase ends when time expires or the mission is lost; the result screen reports planets reached, specimens secured, meteors destroyed, and final score.

### Online crew

1. Enter a crew callsign.
2. Choose **Online Crew**.
3. Create a public/private room or join an existing room by code.
4. Share the code with your crew.
5. The host launches once at least two players are present.

The host advances the authoritative simulation and periodically publishes the world state. Other players send only their current controls. Players who stop checking in for 45 seconds are removed; empty rooms are cleaned up after 30 minutes.

## Project layout

| File | Purpose |
| --- | --- |
| `index.html` | Complete game client: markup, styling, Canvas renderer, game loop, input handling, audio, networking, and UI. |
| `cosmicark.php` | Dependency-free multiplayer room API, session identity, locked JSON persistence, validation, chat, and state synchronization. |
| `cosmicark-smoke.js` | Node-based smoke test that evaluates the inline client script with a lightweight browser mock. |
| `cosmicark-assets.mtl` | Material definitions used by the OBJ parser. |
| `ark.obj` | Ark model. |
| `shuttle.obj` | Rescue shuttle model. |
| `meteor.obj` | Meteor model. |
| `beastie.obj` | Rescue creature model. |
| `turret.obj` | Turret model. |

The game intentionally keeps the client in one HTML file so it can be deployed as a small static package. `cosmicark-smoke.js` extracts the inline `<script>` block at test time; keep the main client script in that block unless the smoke test is updated too.

## Multiplayer API

The browser sends same-origin form-encoded `POST` requests to `./cosmicark.php`. A `GET` request with `?action=list` returns public rooms.

Supported actions are:

| Action | Description |
| --- | --- |
| `list` | List active public rooms. |
| `create` | Create a room and add the current session as host. |
| `join` | Join a room by six-character code. |
| `poll` | Refresh membership, room status, chat, and world state. |
| `chat` | Add a rate-limited chat message. |
| `start` | Host-only launch; requires at least two players. |
| `input` | Submit the current player's controls. |
| `state` | Host-only shared world-state synchronization. |
| `leave` | Remove the current session from a room. |

Responses are JSON objects with an `ok` boolean. Successful room operations include a sanitized `room` payload. Errors include an HTTP error status and an `error` message.

### Storage and permissions

The room store is created beside `cosmicark.php` as `.cosmicark_rooms.store.php`. It begins with a PHP 404 guard so it cannot be downloaded as raw JSON if the server is misconfigured. The API uses shared/exclusive `flock()` locks for concurrent reads and writes.

For production, make sure the PHP worker can write this one directory while preventing directory listing. If the application is deployed in a multi-server or autoscaled environment, replace the file store with a shared datastore; the current implementation is intentionally designed for a single server.

## Testing

Run the smoke test with Node.js:

```bash
node cosmicark-smoke.js
```

Expected output ends with:

```text
Cosmic Ark runtime smoke test OK: iPhone sizing, cardinal meteor lanes, shower, rescue initialization, OBJ fallback renderer.
```

PHP syntax can be checked with:

```bash
php -l cosmicark.php
```

For a manual test, verify all five OBJ files and the MTL file load, start a solo mission, complete both phases, then create a two-window online room and confirm that each player receives the correct role and shared state.

## Security notes

- Online requests are same-origin and the backend rejects cross-site `POST` requests using `Sec-Fetch-Site` when present.
- Session cookies are `HttpOnly` and `SameSite=Lax`; HTTPS requests also receive the `Secure` flag.
- Player names, room names, room codes, and chat messages are length-limited and sanitized server-side.
- Chat is escaped again before being inserted into the client lobby.
- Mission state is validated for an allowed phase and capped at 180,000 bytes.
- This is a small game backend, not an account system. Do not store secrets or personally identifying information in room names, callsigns, or chat.

## License

No license file is currently included. Add a license before redistributing the project or its models.

