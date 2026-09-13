# Access control in Bolum

Bolum uses Laravel policies, a gate and membership middleware. There is no Spatie Permission package or database table of individually configurable permission names.

| Action | Guest | Workspace member | Workspace owner | Catalog admin |
| --- | --- | --- | --- | --- |
| Read leagues, teams, fixtures, club profiles and standings | Yes | Yes | Yes | Yes |
| Read/edit own profile or change own password | No | Yes | Yes | Yes |
| Read workspace predictions, credits and performance | No | In joined workspace | In owned workspace | Only with membership |
| Request prediction | No | In joined workspace | In owned workspace | Only with membership |
| Top up workspace demo credits | No | No | Yes | Only if also its owner |
| Create catalog records, manage fixtures/results and providers | No | No | No, unless admin | Yes |
| View Operations/telemetry and queue fixture sync | No | No | No, unless admin | Yes |
| View workspace gameweek track record | No | No, unless admin | No, unless admin | Also requires membership |

An account can occupy multiple columns. The local `admin@bolum.test` account is both a catalog admin and Bolum Demo owner. `member@bolum.test` is a Bolum Demo member. Registration creates a normal account that owns its new workspace; it does not grant global admin status.

## Where checks live

- `auth:sanctum` on routes: establishes the current user using a bearer token or browser session.
- `CompanyMember::handle()`: verifies a relationship between the current user and the company in the URL; otherwise 403.
- `CompanyPolicy::generate()`: requires membership. `CompanyPolicy::topUp()`: also requires pivot role `owner`.
- `PredictionPolicy::view()`: verifies membership in the prediction's company. Scoped route binding prevents resolving another company's prediction through the current company URL.
- `manage-catalog` gate in `AppServiceProvider::boot()`: checks the trusted `users.is_admin` flag. Catalog/fixture Form Requests and controller/route gates enforce it on the server.
- `ProfileController`: updates `$request->user()`; the client cannot choose another user ID. Password change verifies the current password and revokes tokens/database sessions.

These checks are separate from field validation and rate limiting. Removing a hidden button or changing an ID in Postman does not bypass them. A valid token issued with `*` abilities does not grant admin or company membership: route policies still apply. Tokens do not currently expose a granular selectable ability-management feature.

There is no general membership-invitation/role-management API, custom permission editor, or admin impersonation feature. Catalog administrators have no universal override for private workspace data. Test 401, 403, scoped 404, and owner/member behavior using separate credentials as described in [Postman](POSTMAN.md).
