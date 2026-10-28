# FreeBSD şablonları

*English: [../freebsd-templates.md](../freebsd-templates.md). İki metin farklıysa İngilizcesi geçerlidir.*

Bu rehber bir FreeBSD 14 veya 15 kurulumunu (amd64, UFS root dosya sistemi) Proxmotion'ın
klonlayabileceği bir Proxmox VM şablonuna çevirir. Linux rehberinin,
[iso-templates.md](iso-templates.md), yapısını izler ve yalnızca FreeBSD'de farklı olanı anlatır;
her koşulun neden var olduğu için Linux rehberinin ilk iki bölümünü okuyun.

Panel bir FreeBSD şablonunu Linux şablonu gibi ele alır: kullanıcı `root`'tur, script'ler `sh`
script'leridir, root parolası ve SSH anahtarları cloud-init'ten gelir. Şablonun işletim sisteminin
slug'ı `freebsd` olduğunda panel, varsayılan guest script'lerinin Linux sürümleri yerine FreeBSD
sürümlerini (`resources/guest/freebsd`: ek adresler, disk büyütme, hostname) çalıştırır; içeriden
kontrolleri de yalnızca FreeBSD'de de bulunan komutları kullanır ([ADR 0048](../../adr/0048-freebsd-templates.md)).

> **Durum:** bu rehber FreeBSD'nin, cloud-init'in, QEMU guest agent'ın ve Proxmox VE'nin
> belgelenmiş davranışını ve kaynak kodunu izler. Henüz gerçek bir Proxmox VE'de denenmedi.
> Lütfen sorunları bildirin.

**İçindekiler**

1. [Bir FreeBSD sunucusu şablondan nasıl doğar](#bir-freebsd-sunucusu-şablondan-nasıl-doğar)
2. [FreeBSD'de neler çalışmaz](#freebsdde-neler-çalışmaz)
3. [Adım 1: Proxmox'ta VM](#adım-1-proxmoxta-vm)
4. [Adım 2: UFS ile, root en sonda kurulum](#adım-2-ufs-ile-root-en-sonda-kurulum)
5. [Adım 3: güncellemeler, cloud-init ve guest agent](#adım-3-güncellemeler-cloud-init-ve-guest-agent)
6. [Adım 4: ağı cloud-init'e bırakın](#adım-4-ağı-cloud-inite-bırakın)
7. [Adım 5: QEMU guest agent](#adım-5-qemu-guest-agent)
8. [Adım 6: root girişi](#adım-6-root-girişi)
9. [Adım 7: kimliği unutturun, küçültün, kapatın](#adım-7-kimliği-unutturun-küçültün-kapatın)
10. [Adım 8: VM'i şablona çevirin](#adım-8-vmi-şablona-çevirin)
11. [Adım 9: panele ekleyin ve test edin](#adım-9-panele-ekleyin-ve-test-edin)
12. [Şablonu güncellemek](#şablonu-güncellemek)
13. [Sorun giderme](#sorun-giderme)

## Bir FreeBSD sunucusu şablondan nasıl doğar

Panel Linux için yaptığının aynısını yapar (klon, ayarlar, büyütülen disk, firewall, başlatma,
agent'ı ve cloud-init'i bekleme, guest script'leri, içeriden kontroller). VM'in içinde işi
FreeBSD'nin araçları yapar:

- Proxmox CloudInit drive'ı Windows dışındaki her OS türü için **NoCloud** biçiminde yazar; bu
  yüzden OS türü *Other* olan bir VM, Linux VM'iyle aynı CD'yi alır. FreeBSD paketlerinden gelen
  cloud-init (`net/cloud-init`) onu okur.
- cloud-init root partition'ını ve dosya sistemini FreeBSD'nin kendi **growfs** servisini
  (`/etc/rc.d/growfs`: `gpart recover`, `gpart resize`, `growfs`) çalıştırarak büyütür, ağı ve
  hostname'i `/etc/rc.conf`'a ve `/etc/resolv.conf`'a yazar, root'un parolasını (`pw`) ve SSH
  anahtarlarını ayarlar. FreeBSD ağ renderer'ı arayüzü Proxmox'un ağ yapılandırmasındaki ada göre
  yeniden adlandırabilir (`ifconfig_vtnet0_name`); panelin script'leri arayüzü MAC adresinden bulur.
- Panel, Linux'taki gibi `cloud-init status` cloud-init'in bittiğini söyleyene kadar bekler. Bunun
  için agent'ın `cloud-init`'i `/usr/local/bin`'de bulması gerekir (adım 3).
- Parola sıfırlama agent'ın `guest-set-user-password` komutunu kullanır; bu komut FreeBSD'de
  `pw usermod` çalıştırır.

## FreeBSD'de neler çalışmaz

| Özellik | Neden | Ne yapmalı |
|---|---|---|
| Uygulamalar | Uygulama çalıştırıcısı (`run.sh`) bash ve `systemd-run` gerektirir | Hiçbir uygulama için bir FreeBSD sürümünü işaretlemeyin (*Uygulamalar → Sistemleri seç*) |
| Sunucu sayfasındaki *Disk alanı* kartı | Eski FreeBSD guest agent'ları `guest-get-fsinfo`'yu desteklemez (QEMU bunu FreeBSD için 2026'da ekledi) | Bir şey gerekmez; böyle bir agent'la kart agent'ın yanıt vermediğini söyler |
| Görev listesindeki *Script'i durdur* (ADR 0043 §7) | Bir çalışmanın süreçlerini bulmak Linux'un `/proc/*/environ`'unu gerektirir | Görev yine biter; script sunucunun içinde kendisi bitene kadar çalışır |
| cloud-init'inkinden farklı bir birincil IPv6 adresi | FreeBSD giden IPv6 bağlantılarının kaynak adresini varsayılan rotaya göre değil RFC 6724'e göre seçer | IPv4'te bir şey gerekmez (varsayılan rotanın `-ifa`'sı belirler); IPv6'da eski adres arayüzde durdukça kaynak olarak kalabilir |

Geri kalan her şey (oluşturma, yeniden kurma, güç, konsol, parolalar, SSH anahtarları, ek adresler,
birincil adres değişiklikleri, hostname değişiklikleri, disk yükseltmeleri, taşımalar, firewall,
trafik, içeriden kontroller) Linux'taki gibi çalışır.

## Adım 1: Proxmox'ta VM

`FreeBSD-<version>-RELEASE-amd64-disc1.iso`'yu `download.freebsd.org`'dan *Download from URL* ile,
yanındaki `CHECKSUM.SHA256-FreeBSD-<version>-RELEASE-amd64` dosyasında yazan SHA256 checksum'ıyla
indirin. Sonra sunucularınızın aralığı dışında bir VMID ile *Create VM*:

| Ayar | Değer | Neden |
|---|---|---|
| OS type | *Other* | Linux'taki gibi NoCloud CloudInit drive |
| QEMU Agent | işaretli | guest agent'ın kanalı |
| Disk | 10 GiB, SCSI, *VirtIO SCSI single*, Discard, SSD emulation | küçük şablon, paket başına büyütülür; FreeBSD ona `da0` der |
| CPU | varsayılan tür (`x86-64-v2-AES`), *host* değil | klonlar her node'da başlamalıdır |
| Network | VirtIO (`vtnet0`) | |
| Resource pool | token'ı tek bir pool'la sınırlıysa panelin pool'u | token yalnızca gördüğünü klonlar |

Oluşturduktan sonra: *Hardware → Add → VirtIO RNG*. Her klon, şablonun son kapanışında kaydettiği
entropi dosyasıyla (`/boot/entropy`, `/entropy`) başlar; VirtIO RNG (`virtio_random`, adım 3) her
açılışta host'tan taze rastgelelik katar, böylece klonların rastgele sayı üreteci aynı durumdan
başlamaz.

## Adım 2: UFS ile, root en sonda kurulum

Linux rehberindeki kural geçerlidir: root dosya sistemi **son** partition'dır ve arkasında swap
yoktur; çünkü panel diskin sonuna boş alan ekler ve growfs yalnızca son partition'ı büyütür.
FreeBSD'nin rehberli UFS düzeni swap'i root'tan **sonra** koyar, bu yüzden onu silin:

1. ISO'dan açın, *Install*, bir klavye düzeni, herhangi bir hostname.
2. *Distribution Select*: isteğe bağlı her bileşenin işaretini kaldırın (temel sistem her zaman
   kurulur).
3. *Partitioning*: *Auto (UFS)*, *Entire Disk*, *GPT*. Partition düzenleyicisinde `freebsd-swap`
   partition'ını seçin, *Delete*, sonra *Finish* ve *Commit*. Geride kalan boş alanı büyüyen ilk klon
   kullanır.
4. Geçici bir root parolası. *Network*: `vtnet0`'ı kurulum için yapılandırın (adım 4'te kaldırılır).
   *Time zone*: UTC.
5. *System Configuration*: `sshd` açık, `dumpdev` kapalı (crash dump'lar bir swap aygıtı gerektirir).
   *Hardening*: varsayılanlar. *Add users*: No. Çıkın ve yeniden başlatın.

İlk açılıştan sonra kontrol edin:

```sh
gpart show da0      # freebsd-ufs is the last partition, only free space after it
swapinfo            # lists no swap device
```

ZFS de growfs ile çalışır (`zpool online -e`), ancak sunucu başına daha fazla bellek ister ve
rehberli ZFS düzeni pool'un önünde bir swap partition'ı bırakır; bu yüzden bu rehber UFS kullanır.

## Adım 3: güncellemeler, cloud-init ve guest agent

Konsolda root olarak girin. Önce güncellemeleri kurun ve bir kez yeniden başlatın: cloud-init
etkinleştirildikten sonra şablon bir daha açılmamalıdır; çünkü CloudInit drive olmadan bir açılış
cloud-init'i veri kaynağı olmadan çalıştırır ve o da varsayılan kullanıcısı `freebsd`'yi oluşturur
(yine de olduysa adım 7 onu siler).

```sh
PAGER=cat freebsd-update fetch install
pkg bootstrap -y && pkg upgrade -y
reboot
```

Temel sistem paketleriyle (pkgbase) kurulmuş FreeBSD 15 yalnızca `pkg upgrade` ile güncellenir.

Sonra cloud-init'i ve agent'ı kurup ayarlayın:

```sh
pkg install -y net/cloud-init qemu-guest-agent
sysrc cloudinit_enable=YES qemu_guest_agent_enable=YES
sysrc qemu_guest_agent_env="PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin"
sysrc growfs_swap_size=0
sysrc -f /boot/loader.conf virtio_console_load=YES virtio_random_load=YES
```

| Ayar | Neden |
|---|---|
| `cloudinit_enable` | cloud-init'in rc.d script'lerinin (`dsidentify`, `cloudinitlocal`, `cloudinit`, `cloudconfig`, `cloudfinal`) tek anahtarı |
| `qemu_guest_agent_env` | `/etc/rc` ve `service` daemon'ları `PATH=/sbin:/bin:/usr/sbin:/usr/bin` ile başlatır. Panelin agent üzerinden çalıştırdığı komutlar bunu devralır; `/usr/local/bin` olmadan cloud-init kontrolü `cloud-init`'i bulamaz ve panel beklemeyi hemen bırakır (rc.subr `${name}_env`'i `env(1)`'e verir) |
| `growfs_swap_size=0` | aksi halde cloud-init'in ilk açılışta çalıştırdığı growfs, `/etc/fstab`'ta swap yoksa 15 GB'tan büyük disklerin sonuna bir swap partition'ı ekler. O zaman root artık son partition olmaz ve bir daha büyüyemez. Panelin FreeBSD disk script'i de bunu ayarlar |
| `virtio_console_load` | agent'ın üzerinden konuştuğu VirtIO seri sürücüsü (`/dev/vtcon/org.qemu.guest_agent.0`); GENERIC kernel'de yoktur |
| `virtio_random_load` | adım 1'deki VirtIO RNG |

cloud-init'e nereye bakacağını söyleyin. FreeBSD'de yapılandırması `/usr/local/etc/cloud/`
altındadır:

```sh
cat > /usr/local/etc/cloud/cloud.cfg.d/99-proxmotion.cfg <<'EOF'
datasource_list: [NoCloud, None]
disable_root: false
ssh_pwauth: true
EOF
```

Listede tek bir veri kaynağı (artı `None`) olduğunda `ds-identify` CD'yi kendisi tanımak zorunda
kalmadan onu kullanır. `disable_root` ve `ssh_pwauth` adım 6'ya aittir. FreeBSD'nin cloud-init'i
root'u zaten açık tutar; satır bunu belgeler.

## Adım 4: ağı cloud-init'e bırakın

Linux'taki gibi, kurulum programının ayarları cloud-init'in her klonda yazdıklarıyla çatışırdı.
FreeBSD'de bunlar `/etc/rc.conf`'taki satırlar ve `/etc/resolv.conf` dosyasıdır. cloud-init'in
FreeBSD renderer'ı kendi `ifconfig_*` satırlarını ve `defaultrouter`'ı yazar ama başkalarını asla
silmez; name server'larını da var olan bir `resolv.conf`'a ekler, böylece kurulum ağınınkiler
kalırdı. `rc.conf` satırlarını şimdi kaldırın (ağ bir sonraki açılışa kadar çalışmaya devam eder);
`resolv.conf`'u adım 7 siler.

```sh
sysrc -ix ifconfig_vtnet0 ifconfig_vtnet0_ipv6 defaultrouter ipv6_defaultrouter rtsold_enable
grep -E '^(ifconfig_|defaultrouter|ipv6_defaultrouter|rtsold)' /etc/rc.conf    # prints nothing
```

## Adım 5: QEMU guest agent

VirtIO console sürücüsünü yükleyin ve agent'ı yeniden başlatmadan çalıştırın (bir sonraki açılıştan
itibaren sürücüyü `loader.conf` yükler):

```sh
kldload virtio_console
service qemu-guest-agent start
ls /dev/vtcon/                    # org.qemu.guest_agent.0
```

FreeBSD'nin agent'ı varsayılan olarak hiçbir şeyi kısıtlamaz: `guest-exec` ve dosya komutları
çalışır. VM çalışırken node kabuğundan kontrol edin; her komut `exitcode 0` ile cevap vermeli,
üçüncüsü `/usr/local/bin/cloud-init` yazdırmalıdır (yazdırmıyorsa `qemu_guest_agent_env` eksiktir:
ayarlayın ve servisi yeniden başlatın):

```bash
qm guest cmd <vmid> ping
qm guest exec <vmid> -- id
qm guest exec <vmid> -- sh -c 'command -v cloud-init'
qm guest exec <vmid> -- mkdir -p /var/lib/proxmotion/check
qm guest exec <vmid> -- rmdir /var/lib/proxmotion/check /var/lib/proxmotion
```

## Adım 6: root girişi

FreeBSD'nin sshd'si root'u varsayılan olarak reddeder (`PermitRootLogin no`). sshd okuduğu ilk
değeri tutar ve FreeBSD'nin `sshd_config`'inde bu ayarlar yalnızca yorum olarak bulunur, bu yüzden
onları sona ekleyin:

```sh
printf 'PermitRootLogin yes\nPasswordAuthentication yes\n' >> /etc/ssh/sshd_config
sshd -T | grep -E '^(permitrootlogin|passwordauthentication) '
sysrc sshd_enable=YES
```

`ssh_pwauth: true` (adım 3) cloud-init'in de parolalara izin vermesini sağlar. Yalnızca anahtarla
giriş için `PermitRootLogin prohibit-password`, `PasswordAuthentication no`,
`KbdInteractiveAuthentication no` (FreeBSD parolayı PAM'in keyboard-interactive yöntemiyle de sorar)
ve `ssh_pwauth: false` kullanın; müşterilerin o zaman bir SSH anahtarı olması gerekir.

## Adım 7: kimliği unutturun, küçültün, kapatın

```sh
pw usershow freebsd > /dev/null 2>&1 && pw userdel -n freebsd -r
pw usermod root -w no             # cloud-init sets the real password
cloud-init clean --logs --seed
rm -f /etc/hostid /etc/machine-id /etc/ssh/ssh_host_*
rm -f /etc/resolv.conf /var/db/dhclient.leases.*
rm -rf /tmp/* /var/tmp/*
find /var/log -type f -exec truncate -s 0 {} +
pkg clean -ay
rm -f /root/.sh_history /root/.history
poweroff
```

| Ne | Neden |
|---|---|
| `freebsd` kullanıcısı | cloud-init'in FreeBSD'deki varsayılan kullanıcısı; şablon cloud-init açıkken bir kez bile açıldıysa oluşturulmuştur |
| root'un parolası | cloud-init gerçeğini ayarlayana kadar her sunucuda çalışırdı |
| cloud-init durumu | yoksa cloud-init klonu aynı instance sanar ve işini atlar |
| `/etc/hostid`, `/etc/machine-id` | host'un kimliği; `/etc/rc.d/hostid` ikisini de açılışta, Proxmox'un her klona verdiği SMBIOS UUID'sinden yeniden oluşturur |
| SSH host anahtarları | sshd'nin rc.d script'i ve cloud-init yenilerini oluşturur |
| `resolv.conf`, DHCP kiraları | kurulum ağınınkiler (adım 4) |
| log'lar, geçici dosyalar, paket önbelleği, kabuk geçmişi | yalnızca her klonu büyütür |

Bundan sonra VM'i bir daha başlatmayın: ilk açılışı ona yeniden bir kimlik verir. Gerekirse bu adımı
tekrarlayın.

## Adım 8: VM'i şablona çevirin

Linux'taki gibi: CD/DVD sürücüsünü kaldırın, diskin storage'ında *Hardware → Add → CloudInit Drive*
(*Cloud-Init* sekmesini boş bırakın), *Options → Boot Order* yalnızca `scsi0`, *QEMU Guest Agent*
açık, *More → Convert to template*. Local storage'da şablon, sunucu barındıran her node'da
bulunmalıdır.

## Adım 9: panele ekleyin ve test edin

1. Token'ı tek bir pool'la sınırlıysa şablonu panelin resource pool'una ekleyin ve cluster'ı
   senkronize edin (*Admin → Cluster'lar*).
2. *Admin → OS şablonları*: Proxmox şablonunun adında `freebsd` geçiyorsa *Proxmox'ta bulunanlar*
   *FreeBSD* sistemini ve sürümü önerir. FreeBSD işletim sisteminin platformu *Linux*, slug'ı
   `freebsd`'dir; panel bu slug sayesinde FreeBSD script'lerini kullanır (şablonun satırındaki
   *Script'ler* bunları varsayılan olarak gösterir). Sistemi *İşletim sistemleri* altında elle
   eklerseniz slug'ı `freebsd` olsun diye adını *FreeBSD* koyun.
3. Yeni sürümü hiçbir uygulama için işaretlemeyin.
4. **İki** test sunucusu oluşturun ve kontrol edin:

| Kontrol | Beklenen |
|---|---|
| Görev not bırakmadan biter, sunucu sayfası guest agent'ı gösterir | cloud-init ve agent çalışıyor; içeriden kontroller geçiyor |
| Parolayla ve anahtarla `ssh root@<adres>` | root girişi |
| `ifconfig`, `netstat -rn`, `hostname`, `cat /etc/resolv.conf` | panelin adresi, gateway'i, adı ve DNS'i |
| `df -h /` | paketin disk boyutu |
| İki sunucuda `cat /etc/hostid` ve `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` | her birinde farklı |
| *IP adreslerini düzenle* bir adres ekler, yeniden başlatma onu korur | FreeBSD adres script'i ve onun `proxmotion_addresses` rc.d script'i |
| Daha büyük bir diskle *Kaynakları değiştir* | `df -h /` yeniden başlatmadan büyür (FreeBSD disk script'i) |
| *Hostname değiştir*, sonra yeniden başlatma | `hostname`'de ve `/etc/hosts`'ta yeni ad (FreeBSD hostname script'i ve onun `proxmotion_hostname` rc.d script'i) |

## Şablonu güncellemek

1. Şablonu *Clone* ile kopyalayın (mod **Full clone**). Klonun *Cloud-Init* sekmesinde *User*
   `root` ve geçici bir *Password* belirleyin, başlatın ve konsolda girin.
2. `PAGER=cat freebsd-update fetch install` (pkgbase'de yalnızca `pkg upgrade`), `pkg upgrade -y`,
   yeniden başlatın. 3–6. adımların hâlâ geçerli olduğunu kontrol edin, 7. adımı tekrarlayın ve
   *Cloud-Init* kullanıcısını ve parolasını temizleyin.
3. Yeni, tarihli bir adla şablona çevirin, cluster'ı senkronize edin ve OS şablonunu ona eşleyin.

Yeni bir sürüme geçmek (14 → 15) için yeni ISO'dan yeni bir şablon hazırlamak daha iyidir.

## Sorun giderme

| Gördüğünüz | Olası neden |
|---|---|
| Sunucunun aktif olması 10 dakika sürüyor, *guest agent yanıt vermedi* | `/boot/loader.conf`'ta `virtio_console_load` eksik, agent etkin değil ya da şablonun seçeneklerinde *QEMU Guest Agent* kapalı (adım 3 ve 5) |
| Sunucu hemen aktif oluyor, ama oluşturmadan hemen sonra hostname veya `/etc/hosts` yanlış | Agent `cloud-init`'i bulamıyor, bu yüzden panel onu beklemedi: `qemu_guest_agent_env` (adım 3) |
| *cloud-init 10 dakika sonra hâlâ başlamamıştı* | `cloudinit_enable` eksik ya da `/usr/local/etc/cloud/` içinde bir `cloud-init.disabled` dosyası var |
| Sunucuda şablonun adresi var ya da hiç adres yok | `/etc/rc.conf`'ta `ifconfig_vtnet0` veya `defaultrouter` kalmış (adım 4); `/var/log/cloud-init.log` |
| `df -h /` şablonun boyutunu gösteriyor | Root son partition değil (adım 2) ya da `growfs_swap_size=0` eksik olduğu için growfs root'tan sonra bir swap partition'ı eklemiş: `gpart show da0` |
| *Disk büyütüldü* adımı `/etc/rc.d/growfs is missing` veya bir growfs hatası not ediyor | Çalışmanın çıktısı OS şablonundaki *Script'ler* altındaki log'dadır |
| *Ek IP adresleri* adımı *No network interface with MAC address* not ediyor | VM'in `net0` MAC'i arayüzünkinden farklı; `ifconfig` |
| Root parolası SSH'ta reddediliyor ama konsolda çalışıyor | `PermitRootLogin` (adım 6): `sshd -T` |
| Sunucularda bir `freebsd` kullanıcısı var | Şablon cloud-init açıkken açılmış ve adım 7 atlanmış |
