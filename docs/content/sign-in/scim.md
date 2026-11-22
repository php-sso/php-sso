---
title: "SCIM: provisioning from a company's system"
weight: 3
lead: "Create and remove accounts from a company's directory."
---

A company's system (Okta, Entra, or another) can create, update and deactivate its people here with SCIM 2.0, at
`<SSO_PUBLIC_URL>/scim/v2`. This is separate from SAML: it works whatever way those people sign in. Add a client at
**/admin/security/scim** with a name and the email domains it may manage, and give its token to the provider as a bearer
token. The token is shown once and only its hash is kept; make a new one if it is lost or exposed.

Users and groups: `POST`, `GET` (with `filter=userName eq "..."`, which providers use to look people up, and
also `externalId`, `id` and `emails.value`; for groups `displayName`, `externalId` and `id`), `PUT`, `PATCH` and `DELETE`,
plus `ServiceProviderConfig`, `ResourceTypes` and `Schemas`. A person needs a `userName` and an email address in `emails`.

- **Scope.** A client only creates and changes people whose email is at its domains, only manages people it created
  (or took over, see below), and never touches an administrator.
- **Allowed email domains.** The server-wide list (Security, Accounts) also applies: a client cannot create a person, or move
  someone's address, to a domain outside it (`400 invalidValue`). It does not stop a client taking over an account that exists, or
  changing someone whose address stays as it is.
- **No password.** People it creates have no password, no administrator rights and no group. They sign in another way
  (social, SAML, or a password you give them). A `password` sent by the provider is ignored.
- **Deactivating** (`active: false`) locks the account and ends its sessions at once. Reactivating lets the person back in.
- **Delete** is the client's setting: by default it deactivates (the record and what apps know of the person stay),
  or it can delete the account for good.
- **Taking over** (off by default): with it on, provisioning someone whose address already belongs to an account made some
  other way (not an administrator, not managed by another client) takes that account over instead of refusing.
- **Overwritten.** A name or email changed by hand for a provisioned person is overwritten at the next sync, and an
  administrator who locks such a person can be undone by the provider. The person's page says it is provisioned.
- Every request is recorded on the client's page (when, what, result). SCIM calls carry no cookie and start no session.

**Groups.** Group names reach apps in the `groups` claim, and roles are given to groups, so they get extra care:

- A client only manages groups it created, and can only put people it manages in them (anyone else is refused, and
  the whole request changes nothing).
- A group whose name already exists is refused (409), so a provider cannot take over one of yours. The client's
  "Take over existing groups with the same name" switch (off by default) allows it, and the group's members are then
  replaced by the provider's. Another client's group is never taken over.
- The group's name for apps is the client's optional **prefix** plus the name it sent, with every run of characters
  other than letters, digits and `_ . : -` turned into a dash (`All Employees` becomes `All-Employees`, or
  `acme:All-Employees` with the prefix `acme:`). It must come to 1 to 64 characters. The provider keeps seeing its own
  name, and renaming a group changes what apps see.
- `PATCH` handles adding, removing (also `members[value eq "id"]`, as Entra sends it) and replacing members, and `PUT`
  replaces the whole group. Deleting a group deletes it, with its memberships and the roles given to it.
  Deactivating a person leaves their groups alone.
- A group's page says it is provisioned, and changes made to it by hand are overwritten at the next sync.

Not supported yet: bulk, sorting, ETags, filters other than `eq`, and pushing people out to apps.
