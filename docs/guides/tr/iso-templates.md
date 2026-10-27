# Kurulum ISO'sundan Linux şablonları

*English: [../iso-templates.md](../iso-templates.md). İki metin farklıysa İngilizcesi geçerlidir.*

Bu rehber, neredeyse her Linux dağıtımını Proxmotion'ın klonlayabileceği bir Proxmox VM şablonuna
çevirmeyi anlatır. Her dağıtım için ayrı bir script vermez. Bunun yerine bir şablonun neyi
sağlaması gerektiğini, nedenini, yaygın dağıtım ailelerinde buna nasıl ulaşılacağını ve her
noktanın nasıl kontrol edileceğini anlatır; böylece bu rehberde adı geçmeyen bir dağıtım için de
şablon hazırlayabilirsiniz.

Ubuntu ve Debian için [os-templates.md](../os-templates.md)'deki cloud image yolu daha hızlıdır.
Windows'un kendi rehberi var: [windows-templates.md](windows-templates.md).

> **Durum:** bu rehber Proxmox VE'nin, cloud-init'in ve kurulum programlarının belgelenmiş
> davranışını izler. Şimdiye kadar gerçek bir Proxmox VE ile yalnızca bir Debian 13 şablonu
> kullanıldı; diğer dağıtımlarla ilgili notlar denenmedi. Lütfen sorunları bildirin.

**İçindekiler**

1. [Bir sunucu şablondan nasıl doğar](#bir-sunucu-şablondan-nasıl-doğar)
2. [Altı koşul](#altı-koşul)
3. [Dağıtım aileleri bir bakışta](#dağıtım-aileleri-bir-bakışta)
4. [Adım 1: Proxmox'ta VM](#adım-1-proxmoxta-vm)
5. [Adım 2: doğru disk düzeniyle kurulum](#adım-2-doğru-disk-düzeniyle-kurulum)
6. [Adım 3: cloud-init](#adım-3-cloud-init)
7. [Adım 4: ağı cloud-init'e bırakın](#adım-4-ağı-cloud-inite-bırakın)
8. [Adım 5: QEMU guest agent](#adım-5-qemu-guest-agent)
9. [Adım 6: root girişi](#adım-6-root-girişi)
10. [Adım 7: kimliği unutturun, küçültün, kapatın](#adım-7-kimliği-unutturun-küçültün-kapatın)
11. [Adım 8: VM'i şablona çevirin](#adım-8-vmi-şablona-çevirin)
12. [Adım 9: panele ekleyin ve test edin](#adım-9-panele-ekleyin-ve-test-edin)
13. [systemd'siz dağıtımlar](#systemdsiz-dağıtımlar)
14. [Şablonu güncellemek](#şablonu-güncellemek)
15. [Sorun giderme](#sorun-giderme)

## Bir sunucu şablondan nasıl doğar

Şablona ne olduğunu bilmek, sonraki her kuralı açıklar. Panel bir sunucu oluştururken:

1. Şablonu **klonlar**: diskin tam bir kopyası (full clone) ya da onu paylaşan bir linked clone
   ([ADR 0027](../../adr/0027-linked-clones.md)).
2. **VM ayarlarını yazar**: çekirdek, bellek, sunucunun MAC adresiyle ağ kartı ve cloud-init
   alanları: `ciuser=root`, root parolası, SSH anahtarları, IP adresleri (`ipconfig0`), DNS
   sunucuları ve VM adı (hostname).
3. **Diski** paket boyutuna **büyütür**. Yalnızca sanal disk büyür; içindeki partition'lar ve dosya
   sistemi hâlâ şablonun boyutundadır.
4. VM'in Proxmox **firewall**'unu kurar (anti-spoofing, izolasyon, sunucunun kuralları).
5. VM'i **başlatır**. Proxmox cloud-init alanlarını küçük bir CD'ye çevirir (*CloudInit drive*,
   etiketi `cidata`, Linux için NoCloud biçimi).
6. VM'in içinde **cloud-init** ilk açılışta bu CD'yi okur: root partition'ını ve dosya sistemini
   diski dolduracak şekilde büyütür, ağ yapılandırmasını yazar, hostname'i, root parolasını ve SSH
   anahtarlarını ayarlar ve yeni SSH host anahtarları üretir.
7. Panel önce **QEMU guest agent**'ı, sonra `cloud-init status`'un cloud-init'in henüz başlamadığını
   ya da çalıştığını söylemeyi bırakmasını **bekler** (`not started` / `running`; EL8 ve Debian 12'deki
   gibi 24.1'den eski cloud-init'te `not run` / `degraded running`). Ardından guest script'lerini agent üzerinden çalıştırır (ek adresler, farklı bir
   birincil adres) ve sunucuyu içeriden kontrol eder (adresler, gateway, DNS, hostname, disk).

Yani şablon, **kim olduğunu unutturulmuş** ve şimdi kim olduğunu **cloud-init'in ve guest agent'ın
söylemesine izin veren** kurulu bir sistemdir. Aşağıdaki her şey bundan çıkar.

Bir VM'in CD'sinde ne alacağını node üzerinde her an görebilirsiniz:

```bash
qm cloudinit dump <vmid> user      # parola, SSH anahtarları, hostname
qm cloudinit dump <vmid> network   # adresler, gateway, DNS
```

## Altı koşul

| # | Koşul | Neden | Kontrol |
|---|---|---|---|
| 1 | **Root dosya sistemi diskin son partition'ıdır**, arkasında swap partition'ı yoktur. LVM'den kaçının | cloud-init yalnızca son partition'ı panelin eklediği boş alana büyütebilir | `lsblk` |
| 2 | **cloud-init** kurulu ve etkin, Proxmox CD'sini okuyor ve ağ kısmı kapatılmamış | Panelin ayarladığı her şeyi o uygular | `cloud-init status --long` |
| 3 | **Kurulum programından kalan ağ yapılandırması** yok | cloud-init'in yazdığı yapılandırmayla çatışır | dağıtımınızın ağ dosyaları |
| 4 | **qemu-guest-agent** çalışıyor, komut çalıştırıp dosya yazabiliyor ve VM seçeneklerinde *QEMU Guest Agent* açık | Panel onu bekler, parolaları sıfırlar, script'leri ve uygulamaları onunla çalıştırır | node'da `qm guest exec <vmid> -- id` |
| 5 | **root** SSH ile parolayla ve anahtarla **girebiliyor** | Müşteriler root'un parolasını ve anahtarlarını alır | `sshd -T` |
| 6 | **Kimlik yok**: machine ID, SSH host anahtarları, cloud-init durumu, kurulum parolası yok | Her klon yeni bir makine olmalıdır | `cat /etc/machine-id` boş |

Bir de sanal donanım: CloudInit drive, boot sırasında yalnızca disk, ISO yok.

## Dağıtım aileleri bir bakışta

Dağıtımlar araçlarını birkaç aileden alır. Kendinizinkini burada bulun; aşağıdaki adımlar bu
sütunlara başvurur. Paket adları zaman zaman değişir; biri bulunamazsa dağıtımınızın paket
dizininde programı arayın (`growpart`, `cloud-init`, `qemu-ga`).

| | Debian, Ubuntu (ve Mint, Pardus…) | RHEL ailesi: AlmaLinux, Rocky, Oracle, CentOS Stream, RHEL, Fedora | SUSE: openSUSE Leap/Tumbleweed, SLES | Arch (ve Manjaro…) |
|---|---|---|---|---|
| Paketler | `apt` | `dnf` | `zypper` | `pacman` |
| cloud-init | `cloud-init` | `cloud-init` | `cloud-init` | `cloud-init` |
| `growpart` (cloud-init partition'ı büyütmek için ister) | `cloud-guest-utils` | `cloud-utils-growpart` | `growpart` | `cloud-guest-utils` |
| Guest agent | `qemu-guest-agent` | `qemu-guest-agent` | `qemu-guest-agent` | `qemu-guest-agent` |
| Kurulum programının bıraktığı ağ yığını | Ubuntu: netplan (`/etc/netplan/`). Debian: ifupdown (`/etc/network/interfaces`) | NetworkManager (`/etc/NetworkManager/system-connections/`, eskiler: `/etc/sysconfig/network-scripts/`) | Leap 15: wicked (`/etc/sysconfig/network/ifcfg-*`). Leap 16, Tumbleweed: NetworkManager | ne kurduysanız: systemd-networkd (`/etc/systemd/network/`) veya NetworkManager |
| Agent kısıtlamaları | yok | `/etc/sysconfig/qemu-ga` komutları engeller; SELinux agent'ı kısıtlar | Leap 16'da SELinux (`getenforce` ile bakın) | yok |
| Varsayılan root SSH | Debian: yalnızca anahtar. Ubuntu: root parolası hiç yok | RHEL 9+: kurulumda işaretlenmezse yalnızca anahtar | yeni sürümlerde yalnızca anahtar | yalnızca anahtar |
| Kurulum programının varsayılan düzeni | Ubuntu: LVM. Debian: swap partition'ı | swap'li LVM | Btrfs artı swap partition'ı | sizin seçiminiz |

## Adım 1: Proxmox'ta VM

ISO'yu *Node → ISO image içerik türü olan storage → ISO Images* altında yükleyin (üreticinin
checksum'ıyla *Download from URL*, dosyanın bilgisayarınızdan geçmesini önler), sonra *Create VM*.
Her ayarın bir nedeni var:

| Ayar | Değer | Neden |
|---|---|---|
| VMID, ad | sunucularınızın aralığının dışında (ör. 9000+), `tpl-<dağıtım>-<sürüm>-<tarih>` | Panel VMID'leri kendi aralığından verir; tarih şablonları ayırt ettirir |
| Resource pool | API token'ı bir pool'la sınırlıysa panelin pool'u | Token yalnızca görebildiğini klonlayabilir |
| OS türü | Linux | Proxmox cloud-init CD'sini bu durumda her Linux cloud-init'in okuduğu NoCloud biçiminde yazar |
| BIOS | SeaBIOS (varsayılan) veya OVMF (UEFI); OVMF bir EFI diski ister | İkisi de çalışır; klonlar devralır. Bazı yeni dağıtımlar UEFI ister |
| SCSI denetleyicisi, disk veri yolu | VirtIO SCSI single, SCSI | Hızlıdır ve *Discard*'ı destekler |
| Disk boyutu | küçük, ör. 10 GiB | Her full clone'da kullanılan her blok kopyalanır; panel diski zaten büyütür |
| Discard, SSD emulation | açık | `fstrim` boşalan blokları storage'a geri verebilir, şablon küçülür |
| QEMU Agent | açık | Bu olmadan VM'de agent için kanal olmaz (koşul 4) |
| CPU türü | varsayılan (`x86-64-v2-AES`), `host` değil | `host` şablonu tek bir CPU modeline bağlar; klonlar başka node'larda başlamayabilir |
| Bellek | kurulum programına yetecek kadar (2 GiB) | Panel her sunucunun kendi değerini ayarlar |
| Ağ | VirtIO, internete çıkan bir bridge | Kurulum ve güncellemeler ister; bu yapılandırma 4. adımda kaldırılır |

## Adım 2: doğru disk düzeniyle kurulum

**Kural:** boot loader'ın ihtiyaç duyduğu her şey (SeaBIOS'ta GPT için 1 MiB'lık *BIOS boot*
partition'ı, UEFI'de *EFI system partition*'ı, illa istiyorsanız ayrı bir `/boot`) önce gelir; root
dosya sistemi **en sonda** durur, arkasında hiçbir şey yoktur.

**Neden:** panel sanal diski büyütür, bu da diskin sonuna boş alan ekler. cloud-init'in `growpart`'ı
yalnızca bu boş alana değen partition'ı uzatabilir, sonra üzerindeki dosya sistemini büyütür (ext4,
XFS ve Btrfs bağlıyken büyüyebilir). Root'tan sonra gelen bir swap partition'ı bunu engeller; LVM,
partition ile dosya sistemi arasına bir volume koyar ve cloud-init'in bu volume'u ilk açılışta
büyütüp büyütmediği sürümüne bağlıdır. Panelin kendi büyütme script'i sonraki yükseltmelerde LVM'i
de halleder, ama düz bir partition'da hiçbir şeyin yolunda gitmesi gerekmez.

**Swap:** hiç yok ya da root'un içinde bir swap *dosyası* (Ubuntu kendisi oluşturur). Belleğe panel
karar verir; müşteriler swap ekleyebilir.

Her kurulum programında oraya nasıl ulaşılır:

- **Ubuntu Server:** *Use an entire disk* ve *Set up this disk as an LVM group* işaretini
  **kaldırın**. Geçici bir kullanıcı oluşturun (ör. `setup`), *Install OpenSSH server*'ı işaretleyin,
  snap yok.
- **Debian:** *Manual* bölümlendirme, `/`'a bağlı tek bir birincil ext4 partition'ı, swap yok
  (uyarıyı onaylayın). Bir root parolası belirleyin; istenen kullanıcıyı `setup` olarak oluşturun.
  Yazılım: yalnızca *SSH server* ve *standard system utilities*.
- **RHEL ailesi (Anaconda):** *Installation Destination → Custom → Standard Partition*. İsterse
  `biosboot` veya `/boot/efi` eklemesine izin verin, sonra kalan tüm alanla tek bir `/`; swap'i silin.
  *Minimal Install*. Bir root parolası belirleyin ve *Allow root SSH login with password*'ü
  işaretleyin.
- **openSUSE / SLES:** *Guided Setup*: LVM yok, ayrı home yok, ayrı swap partition'ı yok (snapshot'lı
  Btrfs root olur). Özette SSH servisinin etkin **ve SSH portunun açık** olduğundan emin olun.
- **Arch ve elle kurulumlar:** aynı kural elle; `archinstall` ext4 veya XFS ile, boot loader'ınkiler
  dışında ayrı partition olmadan çalışır.

İlk açılıştan sonra **kontrol**: `lsblk`, root'u `sda`'nın son partition'ı olarak gösterir ve swap
partition'ı yoktur.

```
NAME   SIZE TYPE MOUNTPOINT
sda     10G disk
├─sda1   1M part
└─sda2  10G part /
```

VM yine kurulum programıyla açılırsa *Hardware* altında CD/DVD sürücüsünü *Do not use any media*
yapın.

## Adım 3: cloud-init

Konsolda root olarak girin (Ubuntu: `setup` olarak, sonra `sudo -i`). Sistemi güncelleyin ve
[tablodaki](#dağıtım-aileleri-bir-bakışta) paket adlarıyla `cloud-init`, `growpart` ve guest agent'ı
kurun; örneğin RHEL ailesinde:

```bash
dnf upgrade -y
dnf install -y cloud-init cloud-utils-growpart qemu-guest-agent
```

**Açılışta çalıştığından emin olun.** cloud-init birkaç systemd birimi olarak çalışır
(`cloud-init-local`, `cloud-init` ya da 24.3 sürümünden itibaren `cloud-init-network`,
`cloud-config`, `cloud-final`). Çoğu paket bunları etkinleştirir; bazıları (SUSE, Arch) etkinleştirmez.
Listeleyin ve kapalı olanları etkinleştirin:

```bash
systemctl list-unit-files 'cloud-*'
```

**Nereye bakacağını söyleyin.** cloud-init varsayılan olarak birçok bulutu yoklar, bazılarını ağ
üzerinden; bu Proxmox'ta yalnızca zaman aşımıyla biter. `/etc/cloud/cloud.cfg.d/` içindeki
yapılandırma dosyaları ad sırasıyla okunur ve **sonraki dosya kazanır**; panelin ayarlarını bu
yüzden sona gelen bir dosyaya koyun:

```yaml
# /etc/cloud/cloud.cfg.d/99-proxmotion.cfg
datasource_list: [NoCloud, ConfigDrive, None]
disable_root: false
ssh_pwauth: true
```

`NoCloud` Proxmox'un Linux için yazdığı biçimdir, `ConfigDrive` VM'in cloud-init türü ona
değiştirildiğinde yazdığı, `None` cloud-init'in veri olmadan bitmesini sağlar. `disable_root` ve
`ssh_pwauth` [6. adıma](#adım-6-root-girişi) aittir.

**Onu kapatanları kaldırın.** cloud-init'i kendileri kullanan kurulum programları onu kapatan ya da
"veri kaynağı yok"a sabitleyen dosyalar bırakır. Onları arayın; kendi dosyanız dışında bulduğunuz
her şey silinmeye adaydır:

```bash
ls /etc/cloud/cloud.cfg.d/ /etc/cloud/cloud-init.disabled 2>/dev/null
grep -rl -e 'config: disabled' -e 'datasource_list' /etc/cloud/
```

Ubuntu'da bunlar `subiquity-disable-cloudinit-networking.cfg`, `99-installer.cfg`,
`90-installer-network.cfg` ve varsa `/etc/cloud/cloud-init.disabled`'dır.

**Kontrol:** `cloud-init status --long` `disabled` dememelidir. (Şablonda `done` veya `not started`
der; 7. adımdan sonra `not started` der, bir klonun görmesi gereken de budur. 24.1'den eski
cloud-init `not started` yerine `not run` der.)

## Adım 4: ağı cloud-init'e bırakın

**Neden:** kurulum programı gördüğü ağ için bir yapılandırma yazdı (DHCP veya geçici adresiniz).
Bir klonda cloud-init sunucunun gerçek adresi için kendi yapılandırmasını yazar. Kurulum
programının dosyası kalırsa iki yapılandırma aynı arayüzü sahiplenir: klon şablonun adresiyle
açılabilir, var olmayan bir DHCP sunucusunu bekleyebilir ya da bir sonraki yeniden başlatmada
adresini kaybedebilir.

**Nasıl:** kurulum programının dosyalarını silin, ağ yığınının kendisini değil. cloud-init kurulu
bulduğu ilk yığını seçer (netplan, ifupdown, NetworkManager, networkd, sysconfig) ve onun için,
cloud-init tarafından üretildiği yazan kendi dosyasını yazar.
[Tablonun](#dağıtım-aileleri-bir-bakışta) *ağ yığını* satırını kullanın:

- Ubuntu: `/etc/netplan/` içindeki dosyaları silin.
- Debian: `/etc/network/interfaces`'i loopback'e ve `source` satırına indirin:

  ```
  auto lo
  iface lo inet loopback

  source /etc/network/interfaces.d/*
  ```

- NetworkManager kullanan dağıtımlar: `/etc/NetworkManager/system-connections/` içindeki profilleri
  (ve `/etc/sysconfig/network-scripts/` içindeki eski `ifcfg-eth0` tarzı dosyaları) silin.
- wicked (openSUSE Leap 15): `/etc/sysconfig/network/ifcfg-eth0`'ı silin (`ifcfg-lo` kalsın).
- systemd-networkd: `/etc/systemd/network/` içindeki kendi `.network` dosyalarınızı silin.

VM yeniden başlayana kadar çalışmaya devam eder; 7. adımdan önce ağa tekrar ihtiyacınız olmaz.

**Bir test sunucusunda kontrol** (9. adım): `grep -i 'selected renderer' /var/log/cloud-init.log`
cloud-init'in kullandığı yığını söyler ve arayüzü yalnızca cloud-init'in dosyası yapılandırır.

## Adım 5: QEMU guest agent

**Neden:** agent, panelin sunucuya tek giriş yoludur: panele sunucunun açıldığını söyler, parola
ayarlar, dosya yazar, panelin script'lerini ve uygulamalarını çalıştırır. Panel asla SSH ile girmez.

**Nasıl:** paket 3. adımda kuruldu. Birçok dağıtımda agent servisinin `[Install]` bölümü yoktur ve
VirtIO seri portu göründüğünde udev tarafından başlatılır; bu port da yalnızca VM'in *Options*'ında
*QEMU Guest Agent* açıkken vardır. Yani: seçeneğin açık olduğundan emin olun (değiştirdiyseniz VM'i
kapatıp yeniden başlatın), sonra `systemctl start qemu-guest-agent` ya da etkinleştirilebildiği
yerde `systemctl enable --now qemu-guest-agent`.

**Kısıtlamalar.** Bazı dağıtımlar agent'ın yapabileceklerini sınırlar:

- **RHEL ailesi:** `/etc/sysconfig/qemu-ga` varsayılan olarak `guest-exec`'i ve dosya erişimini
  engeller. Panelin komut çalıştırabilmesi için filtreyi boşaltın:

  ```bash
  sed -i -e 's/^FILTER_RPC_ARGS=.*/FILTER_RPC_ARGS=""/' -e 's/^BLACKLIST_RPC=.*/BLACKLIST_RPC=/' /etc/sysconfig/qemu-ga
  ```

- **SELinux enforcing** (RHEL ailesi; openSUSE Leap 16): agent'ın çalıştırdığı komutlar onun kısıtlı
  domain'inde kalır ve örneğin `/var/lib` altında dizin oluşturamaz, kurulum programı çalıştıramaz.
  Yalnızca bu domain'i permissive yapın; SELinux'un geri kalanı enforcing kalır:

  ```bash
  dnf install -y policycoreutils-python-utils     # semanage'ı sağlar
  semanage permissive -a virt_qemu_ga_t
  ```

VM çalışırken **node'dan kontrol edin**. Bunlar panelin yaptığı türden çağrılardır; her biri cevap
vermeli, `exec` de komutun `exitcode`'unu yazar ve bu `0` olmalıdır:

```bash
qm guest cmd <vmid> ping
qm guest exec <vmid> -- id
qm guest exec <vmid> -- mkdir -p /var/lib/proxmotion/check
qm guest exec <vmid> -- rmdir /var/lib/proxmotion/check /var/lib/proxmotion
```

`ping` cevapsız: servis çalışmıyor ya da VM seçeneği kapalı. `exec` reddedildi: RHEL filtresi.
`mkdir` *Permission denied*: SELinux.

## Adım 6: root girişi

**Neden:** Proxmox `ciuser=root` geçer; cloud-init de müşterinin parolasını ve SSH anahtarlarını
root'a koyar. SSH root'u ya da parolaları reddediyorsa bunun faydası yoktur.

**Buna iki yer karar verir:**

1. **cloud-init:** `disable_root: false` (yoksa root'un `authorized_keys`'ini "başka bir kullanıcıyla
   girin" mesajıyla doldurur) ve `ssh_pwauth: true` (yoksa parolayla girişi kapatır). İkisi de
   [3. adımdaki](#adım-3-cloud-init) dosyadadır.
2. **sshd:** `PermitRootLogin yes` ve `PasswordAuthentication yes`. sshd bir ayar için **ilk**
   okuduğu değeri tutar ve çoğu dağıtım `sshd_config`'in başında `/etc/ssh/sshd_config.d/*.conf`'u ad
   sırasıyla okur. cloud-init'in (`50-cloud-init.conf`) veya cloud image'ların
   (`60-cloudimg-settings.conf`) yazdığı dosyalar `99-…` adlı bir dosyaya üstün gelir. Küçük bir
   numara kullanın:

   ```bash
   printf 'PermitRootLogin yes\nPasswordAuthentication yes\n' > /etc/ssh/sshd_config.d/10-proxmotion.conf
   ```

   `sshd_config`'de `Include /etc/ssh/sshd_config.d/*.conf` satırı yoksa (RHEL 8 ve öncesi) iki
   seçeneği doğrudan `sshd_config`'e yazın.

sshd'nin gerçekte ne yapacağını **kontrol edin**:

```bash
sshd -T | grep -E '^(permitrootlogin|passwordauthentication) '
```

**Şablonun içindeki bir firewall** (firewalld, ufw) SSH'a izin vermeli ya da kapalı olmalıdır. Panel
trafiği VM'in dışında Proxmox firewall'uyla filtreler.

Yalnızca anahtarla giriş de geçerli bir seçimdir: `PermitRootLogin prohibit-password`,
`PasswordAuthentication no` ve `ssh_pwauth: false`. Müşterilerin o zaman bir SSH anahtarı olması
gerekir ve panelin gösterdiği parola yalnızca konsolda çalışır.

## Adım 7: kimliği unutturun, küçültün, kapatın

Bu VM'i *bu* makine yapan her şey gitmeli ki her klon kendininkini oluştursun. Aşağıdaki her satırın
bir nedeni var; yolları dağıtımınıza uyarlayın.

| Ne | Neden | Nasıl |
|---|---|---|
| Geçici kullanıcı | Her sunucuda bilinen bir parolayla var olurdu | `userdel -r setup` (önce root olarak girin; Ubuntu'da root'a `passwd root` ile geçici bir parola verin) |
| root'un parolası | Kurulumdaki parola, cloud-init onu değiştirene kadar geçerli olurdu | `passwd -l root` (gerçeğini cloud-init ayarlar) |
| cloud-init durumu | Yoksa cloud-init klonu aynı instance sanar ve işini atlar | `cloud-init clean --logs --seed` |
| Machine ID | systemd, DHCP ve journald makineyi onunla tanır; klonlar çakışır | `truncate -s 0 /etc/machine-id` (boş, silinmiş değil: systemd boş dosyayı açılışta doldurur). Debian tarzı sistemlerde `/var/lib/dbus/machine-id`'yi de ona bağlayın |
| SSH host anahtarları | Her sunucu bunları paylaşırdı ve müşteriler bir reinstall'dan sonra host key uyarısı görürdü | `rm -f /etc/ssh/ssh_host_*` (yenilerini cloud-init veya `sshd-keygen` üretir) |
| Random seed, DHCP lease'leri | Paylaşılan rastgelelik, eski lease'ler | `rm -f /var/lib/systemd/random-seed /var/lib/dhcp/* /var/lib/NetworkManager/*.lease` |
| Log'lar, geçici dosyalar, önbellekler, geçmiş | Boyut; ayrıca kurulumdan hiçbir şey müşteriye gitmemeli | `apt clean` / `dnf clean all` / `zypper clean -a`, `rm -rf /tmp/* /var/tmp/*`, `journalctl --rotate && journalctl --vacuum-time=1s`, `history -c` |
| Boş bloklar | Full clone yalnızca storage'ın kullanılıyor sandığı blokları kopyalar | `fstrim -av` (diskte *Discard* gerekir) |

Sonra `poweroff`. Bundan sonra **VM'i bir daha başlatmayın**: ilk açılışı ona yeniden bir kimlik
verir. Başlatmanız gerekirse dönüştürmeden önce bu adımı tekrarlayın.

## Adım 8: VM'i şablona çevirin

VM kapalıyken:

1. *Hardware → CD/DVD Drive*: **kaldırın** (yoksa klon ISO'yu taşır).
2. *Hardware → Add → CloudInit Drive*, diskle aynı storage'da. *Cloud-Init* sekmesini boş bırakın:
   panel her sunucu için doldurur.
3. *Options*: *Boot Order*'da yalnızca disk (`scsi0`); *QEMU Guest Agent* açık.
4. İsteğe bağlı: seri konsol için *Hardware → Add → Serial Port* (`serial0`).
5. *More → Convert to template*.

**Local** storage'lı bir cluster'da şablon, sunucu barındıran her node'da bulunmalıdır (*Clone →
Target node*, mod *Full clone*, sonra klonu dönüştürün). **Shared** storage'da bir tane yeter. Bir
**linked clone** şablonun, sunucunun diskinin gideceği storage'da olmasını da ister.

## Adım 9: panele ekleyin ve test edin

1. Panelin API token'ı bir resource pool'la sınırlıysa şablonu o pool'a ekleyin (*Datacenter →
   Permissions → Pools → `<pool>` → Members → Add → Virtual Machine*).
2. *Admin → Cluster'lar*: cluster'ı senkronize edin.
3. *Admin → OS şablonları*: şablon *Proxmox'ta bulunanlar* altında önerilen bir sistem ve sürümle
   görünür; sistem eksikse önce *İşletim sistemleri* altına ekleyin. Sonra yeni sürümü, üzerinde
   sunulacak uygulamalar için işaretleyin (*Admin → Uygulamalar → Sistemleri seç*); o zamana kadar
   o sürümde hiçbir uygulama sunulmaz (ADR 0026). Pasif bir şablon, admin oluşturma formunda bir
   test sunucusu için zaten seçilebilir.
4. **İki** test sunucusu oluşturun ve altı koşulu onlarda kontrol edin:

| Kontrol | Beklenen |
|---|---|
| Görev not bırakmadan biter, sunucu sayfası guest agent'ı gösterir | koşul 2 ve 4 |
| Parolayla ve anahtarla `ssh root@<adres>` | koşul 5 |
| `ip addr`, `ip route`, `hostname`, `cat /etc/resolv.conf` | panelin adresi, gateway'i, adı ve DNS'i; koşul 3 |
| `df -h /` | paketin disk boyutu; koşul 1 |
| İki sunucuda da `cat /etc/machine-id` ve `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` | her birinde farklı; koşul 6 |
| *IP adreslerini düzenle* bir adres ekler, yeniden başlatma onu korur | panelin guest script'leri çalışıyor |
| Daha büyük diskle *Kaynakları değiştir* | `df -h /` yeniden başlatmadan büyür |

## systemd'siz dağıtımlar

Alpine, Void, OpenRC'li Gentoo ve benzerleri cloud-init'e ve guest agent'a sahiplerse şablon
olabilir (Alpine: `apk add cloud-init qemu-guest-agent`, sonra `setup-cloud-init` ve `rc-update add
qemu-guest-agent`): sunucu oluşturma, parolalar ve SSH anahtarları çalışır. Ancak panelin varsayılan
guest script'leri ek adresleri ve değişen hostname'i yeniden başlatmalarda systemd birimleriyle
korur; systemd olmadan bunlar uygulanır ama bir sonraki yeniden başlatmada kaybolur. Şablon için
kendi sürümlerinizi *OS şablonları → Script'ler* altında yazın (ADR 0010) ya da bu şablonları yalnızca
tek adresli paketlerle sunun.

BSD'ler ve Linux olmayan diğer sistemler desteklenmez: panelin script'leri Linux shell script'leridir.

## Şablonu güncellemek

Güvenlik güncellemeleri için şablonları ara ara yenileyin:

1. Şablonu *Clone* edin (mod **Full clone**). Klonun *Cloud-Init* sekmesinde *User* `root` ve geçici
   bir *Password* belirleyin, başlatın ve konsolda girin.
2. Güncellemeleri kurun. 3–6. adımlardan hiçbir şeyin geri alınmadığını kontrol edin (bir güncelleme
   bir yapılandırma dosyasını geri getirebilir), sonra 7. adımı tekrarlayın ve *Cloud-Init* kullanıcısı
   ile parolasını temizleyin.
3. 8. adımı yeni, tarihli bir adla yapın, cluster'ı senkronize edin ve OS şablonunu yeni şablona
   eşleyin.
4. Eski şablonu, onu kullanan hiçbir şey kalmayınca silin. Full clone'lar ona bağlı değildir.
   **Linked clone**'lar temel diskini kullandıkça Proxmox onu silmeyi reddeder: o sunucular
   reinstall edilene ya da sonlandırılana kadar tutun ve eski şablonlar için storage planlayın.

## Sorun giderme

| Gördüğünüz | Muhtemel neden |
|---|---|
| Sunucunun aktif olması 10 dakika sürüyor, *guest agent cevap vermedi* | Agent kurulu değil ya da çalışmıyor, veya şablonun seçeneklerinde *QEMU Guest Agent* kapalı (5. adım) |
| Görev notunda *guest-exec … disabled* veya benzeri | RHEL agent filtresi (5. adım) |
| RHEL ailesi bir sunucuda uygulamalar veya script'ler *Permission denied* ile başarısız | SELinux agent'ı kısıtlıyor (5. adım) |
| Sunucuda şablonun adresi var ya da hiç adres yok | Kurulumdan ağ dosyaları kalmış ya da cloud-init'in ağı kapatılmış (3. ve 4. adım). `/var/log/cloud-init.log` hangisi olduğunu söyler |
| `df -h /` şablonun boyutunu gösteriyor | Root son partition değil, `growpart` eksik ya da root LVM üzerinde (2. adım). `grep -i growpart /var/log/cloud-init.log` |
| Root parolası SSH'ta reddediliyor ama konsolda çalışıyor | sshd root'u veya parolaları reddediyor; `sshd_config.d`'deki daha önceki bir dosya kazanıyor (6. adım). `sshd -T` |
| İki sunucunun machine ID'si veya host anahtarları aynı | 7. adım atlanmış ya da şablon VM ondan sonra başlatılmış |
| Hostname `localhost` ya da şablonun adı | cloud-init çalışmamış (`cloud-init status --long`) |
