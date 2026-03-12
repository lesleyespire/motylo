# motylo - the private chat website

What is Motylo? It's a chat website that has hosting requirements at the bare minimum. It uses polling instead of websockets, though in order to enable voice chat you'll have to make a Pusher account (free). 

For more information, visit my odoo website: https://motylo.odoo.com/

### VISION BOARD AND COMPLETED FEATURES

## General feature collection
- [x] Mobile device UI
- [x] Voice chat
- [x] Typing indicators
- [x] Avatars
- [x] User moderation
- [x] Messages
- [x] Login/Register/guest accounts
- [x] Channel Selection
- [x] Community Selection
- [x] Friend requests and Friends
- [x] One-to-one mesages
- [x] In-site notifications
- [x] Onesignal push notifications
## MOBILE
- [x] Remove emoji button
- [x] We don’t need username on every message
- [x] Lots of pings
- [x] Fix notif bell in room/message
- [ ] Swipe on a message to reply
- [ ] Remove notif on scroll
- [ ] Remove private.php bar in community.php
- [ ] Scrollable friends column 
- [ ] All the private.php changes
## Simple Features
- [x] Notif bell takes you to where you got the notif
- [x] Hover card bio respects enter
- [x] Remove role
- [x] Role badges
- [x] Newline between local roles and bio in hover card 
- [x] Higher priority roles should show higher up in the hover card
- [x] big emojis and emoji button broken.
- [x] Italics, bold, underlined, big text, tiny text, weird fonts 
- [x] Images absolutely huge
- [x] Keep edit window open permanently
- [x] Clicking links changes YOUR page, not the inbuilt page
- [ ] Different bubble hue for each user
- [x] Timeouts - Edit/Reply UI
- [ ] Crop avatars - settings.php
- [ ] Gradient/Shiny Roles
- [x] Delete role
- [x] Timeouts fail for non-owner users ({"ok":false,"error":"missing_mode" ?action=moderate)
- [x] Timeout and ban indicators
- [x] Block people from private.php who don’t have the right roles.
- [ ] Audit edits
- [x] Global timeouts glitch
- [x] We lost the bell sound
- [x] Report message, reply to message, timeout user, ban user, untimeout user, manage roles
- [ ] Timeouts an hour off
- [ ] Enter in messages
## Community Expansion!
# Permissions
- [ ] Clusters in room.php
- [ ] Cluster access
- [ ] Cluster administrator powers
- [ ] Cluster rules
# Community.php (UNIQUE! Collection of interconnected private rooms.)
- [x] Basic Framework
- [x] Complex ids
- [x] Nodes
- [x] Channel layout
- [x] No need for an ‘open button’ just click on the text!
- [x] Role-based perms
- [x] User Interface Polish
- [x] FOR ME, fix sidebar button 
- [x] Full Screen, remove the sidebar!
- [x] There are now two bars, remove it from the view
- [x] Clicking links changes YOUR page, not the inbuilt page
- [x] Remove roles from the top! Admin only can see roles
- [x] Users can wear multiple roles!
- [x] The global role (username colour, private.php rendering)
- [x] You can’t access private.php without perms
- [ ] Channel selection categories
- [ ] Move bell upwards
- [ ] Voice chat channels
- [ ] Individual Channel owners
- [ ] Hidden local roles
- [ ] Hidden channels (Hide when no access)
- [ ] Channels default to category permissions
# Community_admin.php (Allow the owners to edit)
- [x] Basic Framework
- [x] Community Manager Role (Moderation powers)
- [x] Administrator permissions
- [x] Role creation and assignment
- [x] Bans
- [x] Timeouts
- [x] Admin assignment
- [x] Role updates
- [x] Detect owner/admin/moderator (no more id, just select one of your servers)
- [x] Role Hierarchy
- [x] User Interface polish
- [ ] Role creation colour wheel
- [ ] Working audit log
- [ ] Role hierarchy safeguards
- [ ] Customisable moderation
- [ ] Moderation and Interaction Bots
- [ ] Democratic/Dictatorial - Users can decided whether they want their communities to vote for owner (or decided by staff vote) or be a simple dictator who owns it completely. There can also be multiple owners, but one representative (displayed in room.php on hover.)
- [ ] Functional building blocks - this makes it less of a collection of chat rooms than an actual website. 
# Framework
- [x] Channel creation
- [ ] Cluster menu 
- [ ] Cluster owner abilities
- [ ] Private clusters 
- [ ] Cluster in-room admin 
- [ ] Event system. 
- [ ] Simple channel framework
- [ ] Simple building blocks
- [ ] Simple server framework
- [ ] Simple bot building blocks
- [ ] Complex…
# Miscellaneous
- [x] Ban/timeout enforcement private.php page
- [x] Room.php -UPDATE Community reqs
- [x] First community! The simple general chat room!
- [x] room.php (DISPLAY NEW COMMUNITIES! Node linking system!)
- [ ] Unique role for every community
- [ ] user.php UPDATE (little dropdown to choose your role from your list of communities. You can only choose one!)
- [ ] Hover to see community details
- [ ] Mafia community! (Our second node!)
- [ ] Private community clusters
- [ ] Fully private communities
# Permissions
- [x] Access server framework
- [x] Access Channel framework
- [x] Timeout/Untimeout
- [x] Assign Permissions
- [x] Role Assignment
- [x] Ban permissions
- [ ] Hidden Channel access
- [ ] View-lock Access
- [ ] Unquarantine
- [ ] Edit Category permissions (Who can edit in categories)
- [ ] Send-lock Access
- [ ] Send-lock Ability
- [ ] View-lock Ability
- [ ] Delete permissions
- [ ] View deleted messages
- [ ] View edits
- [ ] View audit-log
- [ ] Unban
- [ ] Untimeout
# Later Features
- [x] Stronger favicons
- [ ] Show friends of friends- not just mutual friends 
- [ ] DYNAMIC POLLING (Increases when rapid sending)
- [ ] Sitemap.xml
- [ ] Animations - Nicer buttons
- [ ] Custom loading animations 
- [ ] improve index.php
# Debug Days
- [x] dm/friend push notifications broken.
- [x] room.php bell broke
- [x] Notification bell is broken literally everywhere
- [ ] Block Button movement
- [ ] Mobile Typing Indicators
- [ ] Align mobile message bubbles 
- [ ] Push notifs
- [ ] Image replies

### How to install it for yourself

Edit config.php to include your own database details, and if you use voice chat, pusher credentials. Then upload all the files and folders (including empty folders).

Finally, you just need to edit the database to have the necessary structure, you should be able to import the sql file (database.sql). You will need to edit the file (You can turn it into a text file) and change the database name to your own.

If you plan to use push notificiations, once again, just edit config.php with your credentials.

One that's done, you just need a friend! 

*This project is made possible with chatgpt coding. I'm unfortunately quite terrible at writing my own php at the moment.*

