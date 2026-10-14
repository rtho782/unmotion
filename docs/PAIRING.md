# Pairing hosts

Author: Richard Skinner

The accept/cancel workflow is available in 0.4.8. Both peers require 0.4.7 or later; the initiating host needs 0.4.8 to display this dialog. Wire protocol 7 and the reciprocal pairing exchange are unchanged. Existing 0.4.7 installations use manual fingerprint entry until updated.

Open **Settings → unMotion**, enter the peer address, SSH port and root password, then choose **Pair**. unMotion reads the host's Ed25519 SSH fingerprint and displays **Trust this host?** with the address and port. Choose **Accept and pair** to trust that key and establish the reciprocal pairing, or **Cancel** to return without pairing.

Acceptance uses trust on first use, like an SSH client's first-connection prompt. If you want to verify the identity independently, expand **Verify manually (optional)** and compare the displayed fingerprint with this command on the peer's console or a connection you already trust:

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

The preview does not send the password to the peer or install any keys. After acceptance, unMotion checks the key again and pins only the accepted key. A changed key stops pairing before password authentication. Future connections enforce the saved pin. The return host key travels through the authenticated pairing connection; installed client keys remain restricted to authenticated unMotion operations.

Existing pairings are retained and cannot be silently replaced through this dialog. For an intentional host-key change, verify the replacement and resolve any dependent replication/recovery policies before explicitly removing and recreating the pairing. The password is used once for initial setup and discarded; pair only hosts you fully trust.
