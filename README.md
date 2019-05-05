# MultiPort
Open multiple ports for your server

All you need to do to open a port is add the port to your config so it looks a little like this:
```YAML
# Add as many ports as you like. do it like the example shown without the hashtag.

ports:
 - 19131
 - 19132
 - 19133
 - 25565
```
You can add as many ports as you like. If you try to add the default port, the plugin will do nothing for that port.