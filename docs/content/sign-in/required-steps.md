---
title: "Steps required before signing in"
weight: 5
lead: "Make people do something before they get in."
---

After someone has proved who they are (and passed the second factor, when there is one), the server can still
require something before it creates their session. Today there is one: **choose a new password**. Tick
"Must choose a new password at next sign-in" on the user form (it is on by default for new users, since you chose
their first password). They are asked at `/login/required` before they can continue, and their other sessions
end when they have chosen one. A person who is already signed in when you tick it is stopped at their next visit
to the account page, the admin panel or an app's sign-in, and sent through the step first. People without a local
password (social sign-in) are never asked.
