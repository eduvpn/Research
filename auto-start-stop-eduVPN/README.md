# Introduction
If someone connects to a public network with a device, they are putting themselves at risk. Malicious attackers can capture their network traffic and read private and sensitive information. With a Virtual Private Network (VPN), one can defend themselves against these attackers as it can encrypt the network traffic. Many IT organisations offer VPN services, one of which is [SURF](https://www.surf.nl/en/about-surf). SURF is a cooperative association of Dutch educational and research institutions. In 2014, SURF started a new VPN service called [eduVPN](https://www.surf.nl/en/eduvpn/about-eduvpn?dst=n1173) with the goal to replace typical closed source VPNs with an open source audited alternative. Nowadays, eduVPN is often used by students, researchers, and employees of educational institutions worldwide

Users often have to connect to eduVPN in order to get access to the resources of an organisation. To establish an eduVPN connection with the default settings, the user has to run the eduVPN client and authenticate (if the previous VPN connection is expired). 

However, sometimes the user forgets to establish the eduVPN connection and tries to access the organisation's resources without one. The user is prompted with a generic error message and is confused why he or she can not access the resource. As a result, the user often contacts the eduVPN support regarding the issue. The eduVPN support points out to the user that they need to establish the eduVPN connection in order to get access to the organisation's resources. These kind of trivial support requests can cause unnecessary overhead for the eduVPN support team.

The Windows eduVPN developer has partially mitigated this limitation by offering the option to start the eduVPN client on sign-on. As long as the VPN configuration has not expired, the eduVPN client is able to establish the VPN connection automatically. However, this option is not offered by default. Moreover, if one enables this option for every VPN user the amount of concurrent VPN users can increase tremendously, as the VPN is on most of the time.

It would therefore be useful to find a way in order to automatically start and stop eduVPN so that we minimalize unnecessary overhead for the eduVPN support team and the eduVPN server. This leads to the main research question:

**How can we make eduVPN automatically start and stop?**

We will limit the scope of our study to eduVPN users that have bring your own devices. [For managed devices users we can make eduVPN a system VPN that is always on.](https://github.com/FlorisHendriks98/HTTP_provisioning) Users are always connected to the eduVPN and therefore do not request organisation's resources without one.

# Technical paths

We find out that the WireGuard macOS client partially has auto stop / start functionality using a feature from the [network extension framework](https://developer.apple.com/documentation/networkextension/personal_vpn/vpn_on_demand_rules). The WireGuard client is able to establish a VPN connection when we connect to any network, or define a list of ssid's (see the Figure below). 

![image](https://user-images.githubusercontent.com/47246332/186175859-df8a74dd-7629-48e2-b080-cfa58ad26636.png)

OpenVPN also has such functionality for macOS but unfortunately does not support it natively. Someone made a [Github project](https://github.com/iphoting/ovpnmcgen.rb) that generates a .mobileconfig file which is able to set up or tear down an openvpn connection for a specific SSID. 

Something that is quite remarkable is that both WireGuard and OpenVPN do not have this feature implemented in their Windows clients.

[Microsoft did write documentation about this feature.](https://docs.microsoft.com/en-us/windows/security/identity-protection/vpn/vpn-auto-trigger-profile) It shows how we can automatically trigger a VPN connection for built-in VPNs. However, we can not use this since it does not support WireGuard and OpenVPN. Windows defines the following triggers:
## VPN auto-triggered options
### Application trigger
Microsoft offers the ability to trigger a Windows built-in VPN based on the application that is used.
### Name-based trigger
In windows one also has ability to activate a VPN based on specific or all DNS queries.

This can be useful to implement in eduVPN. Instead of routing network packets via a specific ip adress it can be handy to do it via DNS as it can handle IP changes. 

### Always On
Windows has the ability to enable the VPN when the user signs in, when there is a network change and when the device screen is on. 

eduVPN already has the ability to start on sign-on. One can also extend this functionality for network changes and when the device screen is on. If the user changes to the public network we want to have the VPN route all the traffic and when we are on corporate network we might want to route less over the VPN or perhaps turn the VPN off.

### Untrusted network
Lastly, the Windows built-in VPN can detect if the network is trusted or not. Windows retrieves a list of DNS suffixes and checks if it matches the network name of the physical interface connection profile.

---


## Monitoring events
We can replicate this feature on Windows by monitoring events from event viewer. We can for example create a service that monitors for event id 10000. Event id 10000 is logged whenever you connect to a network. If the network SSID matches a predefined list it will start the VPN. It is more difficult to use this for DNS queries and applications as those are not logged by default, [you need to edit group policy for that](https://superuser.com/questions/1052541/how-can-i-get-a-history-of-running-processes). As an example, in the figure below we made a custom XML file that triggers eduvpn.exe whenever Signal is started. 

![image](https://user-images.githubusercontent.com/47246332/190123129-0973b4e0-3a0b-4cc9-97d6-3fe18d918235.png)


## Network packet filter
During our research we discovered an extended WireGuard client called [WireSock](wiresock.net). One of the extended features it has compared to the regular WireGuard client is that it can tunnel network traffic only for specified applications (e.g. Firefox, Signal etc). They likely have realised this with [ndisapi](https://github.com/wiresock/ndisapi), a Windows Packet Filter. Maybe we can use this to monitor for specific DNS queries.

## Split tunneling
We can define a lot of triggers (e.g. for a specific application, a set of DNS queries, detect if we are on corporate network) to start and stop eduVPN. But in order to realize this we need to monitor quite a lot from the client computer. We wonder if this is feasible and if virus scanners will not obstruct this implementation. Instead of using triggers to determine when we start or stop the VPN we can take a different technical path. Using split tunneling, we can specify what network traffic goes via the VPN tunnel and what traffic does not.

Using eduVPN's sign-on functionality we can have eduVPN always-on for a large portion of the time. Normal traffic will be routed over the regular interface in order to alleviate the resources of the eduVPN server. However, if the user wants to retrieve resources from the organisation we will route it via eduVPN.  
