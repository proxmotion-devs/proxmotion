# Windows şablonları

*English: [../windows-templates.md](../windows-templates.md). İki metin farklıysa İngilizcesi geçerlidir.*

Bu rehber, Proxmotion için bir Windows şablonunun nasıl çalıştığını ve güncel her Windows sürümü
için, **Windows Server 2016, 2019, 2022 ve 2025** ile **Windows 10 ve 11** için, iki ayrı yolla nasıl
hazırlanacağını anlatır: **Sysprep'li** (Microsoft'un desteklediği yol) ve **Sysprep'siz** (daha
hızlı, bilinen bedelleriyle). Her adımın neden orada olduğunu ve sürümlerin nerede ayrıldığını
açıklar; böylece onu kendi imajınıza uyarlayabilir ve sonucu kontrol edebilirsiniz.

Linux şablonlarının kendi rehberi var: [iso-templates.md](iso-templates.md).

**İçindekiler**

1. [Bir Windows sunucusu şablondan nasıl doğar](#bir-windows-sunucusu-şablondan-nasıl-doğar)
2. [Windows sürümleri bir bakışta](#windows-sürümleri-bir-bakışta)
3. [Klonları benzersiz yapmak: Sysprep'li veya Sysprep'siz](#klonları-benzersiz-yapmak-sysprepli-veya-sysprepsiz)
4. [Temel sistemi kurmak](#temel-sistemi-kurmak) (iki yol için): [ISO'lar](#1-isolar) · [VM](#2-vmi-oluşturun) · [Kurulum](#3-windowsu-kurun) · [Hazırlık](#4-windowsu-hazırlayın) · [Administrator](#5-administrator-hesabı) · [Cloudbase-Init](#6-cloudbase-initi-kurun)
5. [Yol A: Sysprep'li](#yol-a-sysprepli)
6. [Yol B: Sysprep'siz](#yol-b-sysprepsiz)
7. [VM'i şablona çevirin](#vmi-şablona-çevirin) (iki yol için)
8. [Panele ekleyin ve test edin](#panele-ekleyin-ve-test-edin)
9. [Yapılandırma dosyalarının açıklaması](#yapılandırma-dosyalarının-açıklaması)
10. [Yeni bir sunucu ne kadar hızlı hazır olabilir](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)
11. [Şablonu güncellemek](#şablonu-güncellemek)
12. [Panel Windows için ne yapar](#panel-windows-için-ne-yapar)
13. [Sorun giderme](#sorun-giderme)
14. [Henüz doğrulanmayanlar](#henüz-doğrulanmayanlar)

## Bir Windows sunucusu şablondan nasıl doğar

Panel bir sunucu oluştururken şablonu klonlar; çekirdekleri, belleği, ağ kartını ve cloud-init
alanlarını (parola, adresler, DNS, VM adı) ayarlar, diski büyütür ve VM'i başlatır. OS türü
**Microsoft Windows** olan bir VM için Proxmox cloud-init alanlarını bir *config drive* olarak yazar
(`config-2` etiketli küçük bir CD):

| CD'deki dosya | İçerik | Okuyan |
|---|---|---|
| `meta_data.json` | `uuid` (*instance ID*), `admin_pass`, SSH anahtarları | Cloudbase-Init: parola |
| `network_data.json` | adresler, gateway, DNS | Cloudbase-Init: ağ |
| `user_data` | `hostname: …` içeren `#cloud-config` | Cloudbase-Init'in `UserDataPlugin`'i: bilgisayar adı |

Proxmox parolayı oraya yalnızca Windows OS türünde düz metin olarak koyar; diğer türlerde Windows'un
kullanamayacağı bir hash saklar.

**Cloudbase-Init**, cloud-init'in Windows karşılığıdır. *Plugin*'lerinin her biri tek bir iş yapar ve
hangi plugin'lerin çalıştığını registry'de instance ID başına hatırlar. Bir klonun instance ID'si
yenidir; bu yüzden her plugin onda bir kez çalışır, sonraki açılışlarda atlanır.

Panel ardından Windows hazır olana kadar bekler (bkz. [Panel ne yapar](#panel-windows-için-ne-yapar)):
guest agent cevap verir, Windows kurulumu `IMAGE_STATE_COMPLETE` bildirir, `cloudbase-init` servisi
yapılandırmasındaki her plugin'i bu sunucunun instance kimliği için tamamlandı olarak kaydedip durur
ve hiçbir yeni bilgisayar adı yeniden başlatma beklemez. Sonra kendi script'lerini (ek adresler)
guest agent üzerinden çalıştırır. Müşteriler
Uzak Masaüstü ile **`Administrator`** olarak girer; panelin parolasını ayarlayıp sıfırladığı hesap
budur.

Yani sürümü ne olursa olsun her Windows şablonunda şunlar gerekir: **VirtIO sürücüleri** (disk ve
ağ), **QEMU guest agent**, etkin yerleşik **`Administrator`** hesabı, config drive'ı okuyacak şekilde
ayarlanmış **Cloudbase-Init**, açık **Uzak Masaüstü** ve her klonu **farklı bir makine** yapacak bir
yol; iki yolun ayrıldığı yer de burasıdır.

## Windows sürümleri bir bakışta

Adımlar her sürüm için aynıdır; bu tablo farklı olanları toplar. Aşağıda satırlarına başvurulur.

| | Server 2016 | Server 2019 | Server 2022 | Server 2025 | Windows 10 (22H2) | Windows 11 (24H2, 25H2) |
|---|---|---|---|---|---|---|
| Proxmox OS türü | `10/2016/2019` | `10/2016/2019` | `11/2022/2025` | `11/2022/2025` | `10/2016/2019` | `11/2022/2025` |
| VirtIO sürücü klasörü | `2k16` | `2k19` | `2k22` | `2k25` | `w10` | `w11` |
| En küçük şablon diski | 32 GiB | 32 GiB | 32 GiB | 32 GiB | 32 GiB | **64 GiB** |
| Kurulum için bellek | 2 GiB | 2 GiB | 2 GiB | 2 GiB | 2 GiB | **4 GiB** |
| TPM 2.0 ve Secure Boot | hayır | hayır | hayır | hayır | hayır | **zorunlu** (TPM state, pre-enrolled keys'li EFI diski) |
| CPU | herhangi | herhangi | herhangi | **x86-64-v2** (SSE4.2, POPCNT) | herhangi | **x86-64-v2**, 2 çekirdek |
| Yerleşik `Administrator` | açık | açık | açık | açık | **kapalı** | **kapalı** |
| Microsoft hesabı olmadan kurulum | evet | evet | evet | evet | evet (çevrimdışı hesap) | Enterprise, Education, IoT LTSC; Pro ve Home yalnızca audit modu veya cevap dosyasıyla |
| Uzak Masaüstü sunucusu | evet | evet | evet | evet | Pro, Enterprise, Education (**Home değil**) | Pro, Enterprise, Education (**Home değil**) |
| Yol A (Sysprep) | evet | evet | evet | evet | evet, audit modundan | evet, audit modundan |
| Yol B (Sysprep'siz) | olur | olur | olur | **hayır** (ortak SID kimlik doğrulamayı bozar) | olur | **hayır** (ortak SID kimlik doğrulamayı bozar) |
| Microsoft desteğinin sonu | Ocak 2027 | Ocak 2029 | Ekim 2031 | Kasım 2034 | **Ekim 2025'te bitti** (yalnızca ESU) | sürüme göre (24 veya 36 ay) |

Tablo Windows'un kendi varsayılanlarını kullanır; tarihler Microsoft'un yayımladığı yaşam döngüsü
tarihleridir. Daha eski sürümlerin (Server 2012 R2, Windows 8.1) desteği bitti ve güncel VirtIO
sürücüleri artık onlar için gelmiyor.

**Lisanslama.** Aktivasyon ve lisanslama (SPLA, AVMA, KMS, MAK) sizin sorumluluğunuzdadır; siz
eklemedikçe şablonda ürün anahtarı yoktur. **Windows 10 ve 11 masaüstü sistemleridir**: Microsoft'un
lisans koşulları bunları SPLA ile üçüncü kişilere kiralamaya izin vermez; istemci Windows'u çok
kiracılı barındırmak yalnızca Microsoft'un Qualified Multitenant Hoster (QMTH) programıyla,
müşterilerin kendi lisanslarıyla mümkündür. İstemci Windows satmadan önce lisans sağlayıcınıza
danışın.

## Klonları benzersiz yapmak: Sysprep'li veya Sysprep'siz

Bir Windows kurulumu her makinede farklı olması gereken değerler taşır. Sysprep'in *generalize*
aşaması bunları kaldırır; her klonun ilk açılışı da Windows kurulumunun *specialize* aşamasını ve
OOBE'yi çalıştırıp yenilerini oluşturur. Sysprep olmadan klon birebir kopya olarak başlar ve önemli
olanları siz kaldırırsınız.

| | Yol A: Sysprep'li | Yol B: Sysprep'siz |
|---|---|---|
| Microsoft desteği | evet, bir kurulumu çoğaltmanın desteklenen tek yolu | hayır |
| Bir klonun ilk açılışı | *specialize* aşaması, bir yeniden başlatma, OOBE, sonra Cloudbase-Init (Server 2022'de klona ek yaklaşık 1,5 dk, ölçüldü) | normal bir açılış, Cloudbase-Init, bilgisayar adı için bir yeniden başlatma (ölçülmedi; belirgin şekilde kısa olması beklenir) |
| Makine SID'i | her klonda yeni | **her sunucuda aynı** |
| Uzak Masaüstü sertifikası | yeni | şablonda silerseniz yeni (B2) |
| Windows Update / WSUS istemci kimliği | yeni | şablonda silerseniz yeni (B2) |
| KMS aktivasyonu | çalışır (yeni istemci makine kimliği) | her klon KMS sunucusuna aynı istemci gibi görünür; MAK, AVMA veya perakende anahtar kullanın |
| Sürücüler | ilk açılışta yeniden algılanır (`PersistAllDeviceInstalls` yoksa) | zaten kurulu |
| Şablonu hazırlamak | Sysprep, tek bir kullanıcı için güncellenmiş Store uygulamalarında durur (sunucularda Edge, Windows 10/11'de birçok uygulama) | Sysprep hatası yok |

**Ortak SID'in anlamı.** Birbirine kimlik doğrulaması yapmayan bağımsız sunucularda aynı makine
SID'i uzun süre pek sorun çıkarmadı. Bu artık her yerde geçerli değil: Microsoft'un sürüm notlarına
göre Eylül 2025 güncellemelerinden beri Windows 11 24H2 ve sonrası ile Windows Server 2025, aynı
SID'e sahip makineler arasında NTLM ve Kerberos kimlik doğrulamasında başarısız oluyor (örneğin bir
müşterinin iki sunucusu arasındaki dosya paylaşımı) ve bu tasarım gereği. Domain controller'lar ve
bazı yönetim yazılımları aynı SID'i hiçbir zaman kabul etmedi.

**Hangisi seçilmeli.** Geniş çapta sattığınız her şey için ve Windows Server 2025 ile Windows 11 için
her zaman Yol A. Yol B yalnızca Server 2016, 2019, 2022 ve Windows 10 için; ilk açılış süresi
destekten daha önemliyse ve bir şablondan oluşan sunucuların SID'i paylaşmasını kabul ediyorsanız.

Panel iki yolda da aynı çalışır; bunun için bir ayar gerekmez.

## Temel sistemi kurmak

1–6. adımlar iki yol için de aynıdır. Bir adım sürümler arasında farklıysa bunu söyler.

### 1. ISO'lar

**ISO image** içerik türü olan bir storage'a (*Node → `local` → ISO Images → Upload* veya *Download
from URL*):

- sürümünüzün **Windows** ISO'su (Server: evaluation ya da volume licensing medyası; istemci: Media
  Creation Tool'un ISO'su ya da volume licensing medyası; Windows 11 için Enterprise veya Education
  3. adımı kolaylaştırır) ve
- Fedora projesinin stable sürümü **VirtIO sürücüleri** ISO'su:
  `https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`.

### 2. VM'i oluşturun

*Create VM*; sunucularınızın aralığı dışında bir VMID (ör. 9000 ve üstü) ve sürümü ile yolu söyleyen
bir adla, ör. `tpl-win2022-sysprep-20261027`. Her ayarın bir nedeni var:

| Sekme | Ayar | Neden |
|---|---|---|
| General | *Resource Pool*: API token'ı bir pool'la sınırlıysa panelin pool'u | Token yalnızca görebildiğini klonlar |
| OS | Windows ISO'su; *Type* **Microsoft Windows** ve [tablodaki](#windows-sürümleri-bir-bakışta) sürüm; `virtio-win.iso` ile **Add additional drive for VirtIO drivers**'ı işaretleyin | OS türü config drive biçimine ve parolanın Cloudbase-Init'e ulaşmasına karar verir; Proxmox'un doğru varsayılanları önermesini sağlar |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)** ve bir EFI diski (pre-enrolled keys; Secure Boot'u açar), *SCSI Controller* VirtIO SCSI single, **QEMU Agent** açık. **Windows 11:** ayrıca *Add TPM*, sürüm v2.0 | Her sürüm için modern firmware; Windows 11 kurulumu TPM 2.0 ve Secure Boot olmadan kurmayı reddeder |
| Disks | Veri yolu SCSI, tablodaki en küçük boyut (32 GiB, Windows 11 için 64 GiB), **Discard** ve **SSD emulation** açık | Panel diski pakete göre büyütür ama asla küçültmez: şablonun boyutu, onun için bir paketin alabileceği en küçük disktir. *Discard* şablonun küçülmesini sağlar (A3/B3) |
| CPU | 1 soket, hazırlarken **4 çekirdek**, *Type* varsayılan (`x86-64-v2-AES`), `host` değil, asla `kvm64` değil | Güncellemeler daha hızlı kurulur; `host` klonları tek bir CPU modeline bağlar; Server 2025 ve Windows 11 `kvm64`'te olmayan x86-64-v2'yi ister. Her sunucunun sayısını panel ayarlar |
| Memory | 4096 MiB, ballooning kapalı | Her sürümün kurulumuna yeter; her sunucunun değerini panel ayarlar |
| Network | Model VirtIO, internete çıkan bir bridge | Güncellemeler. Cloudbase-Init bu yapılandırmayı her klonda değiştirir |

Windows 11'de TPM state şablonun parçasıdır ve her klona kopyalanır; tüm sunucular aynı sanal TPM
kimliğini paylaşır. Bir VPS için zararsızdır; TPM attestation'a güvenmeyin.

### 3. Windows'u kurun

1. Konsol *Press any key to boot from CD or DVD* dediğinde bir tuşa basın.
2. Lisansınız olan sürümü seçin (Server: *Desktop Experience* veya *Core*).
3. *Where do you want to install Windows?* ekranında disk görünmez, çünkü Windows'un VirtIO SCSI
   sürücüsü yoktur: *Load driver* → VirtIO CD'si → `vioscsi\<tablodaki klasör>\amd64`. Görünen diske
   kurun. Windows kendi partition'larını `C:` en sonda olacak şekilde oluşturur; `C:`'nin sonradan
   büyüyebilmesini sağlayan budur. (Windows 11'in yeni kurulum ekranı aynı seçeneği *Load driver*
   altında gösterir.)
4. **Windows Server:** geçici bir Administrator parolası belirleyip girin.
5. **Windows 10 ve 11:** kurulum, bir hesap isteyen OOBE ile biter. Burada ne yapacağınıza
   seçeceğiniz yol karar verir:
   - **Yol A:** ilk OOBE ekranında (bölge) **Ctrl+Shift+F3**'e basın. Windows **audit moduna**
     yeniden başlar; yerleşik Administrator olarak, hiçbir hesap oluşturmadan ve Microsoft hesabı
     sormadan girer. Açılan *System Preparation Tool* penceresini kapatın; Sysprep bitirene kadar her
     yeniden başlatma audit moduna döner. 4–6. adımların ve Yol A'nın tamamını audit modunda yapın.
   - **Yol B:** OOBE'yi geçici bir **yerel** hesapla (ör. `setup`) bitirin. Windows 10 *offline
     account* sunar; Windows 11 Enterprise, Education ve IoT LTSC *Domain join instead* sunar.
     Windows 11 Pro ve Home Microsoft hesabında ısrar eder ve Microsoft bunu aşmanın yollarını
     kaldırıp duruyor; onlar için Yol A'yı ya da kurulum medyasında yerel hesap oluşturan bir
     `autounattend.xml` kullanın.

### 4. Windows'u hazırlayın

1. VirtIO CD'sinde **`virtio-win-guest-tools.exe`**'yi çalıştırın: tüm VirtIO sürücüleri (ağ,
   balloon, seri) ve QEMU guest agent. (Server Core'da komut isteminden başlatın.)
2. *Settings → Windows Update* hiçbir şey sunmayana ve yeniden başlatma istemeyene kadar tüm
   güncellemeleri kurup yeniden başlatın. Şablonda bekleyen güncellemeler **her** yeni sunucuda,
   ek yeniden başlatmalarla tamamlanır.
3. Yönetici olarak açılmış PowerShell'de bir müşterinin beklediğini açın, sunucuyu yalnızca
   yavaşlatanı kapatın:

   ```powershell
   # Remote Desktop (off by default) and its firewall rules; the group is named by its
   # resource string so this works in every display language
   Set-ItemProperty 'HKLM:\System\CurrentControlSet\Control\Terminal Server' -Name fDenyTSConnections -Value 0
   Enable-NetFirewallRule -Group '@FirewallAPI.dll,-28752'
   # Answer ping (IPv4 and IPv6), useful for customers' monitoring
   Enable-NetFirewallRule -Name FPS-ICMP4-ERQ-In, FPS-ICMP6-ERQ-In
   # No hibernation file and no Fast Startup, high-performance power plan
   powercfg /hibernate off
   powercfg /setactive SCHEME_MIN
   # Servers: no Server Manager window at every logon (no such task on Windows 10/11)
   Get-ScheduledTask -TaskName ServerManager -ErrorAction SilentlyContinue | Disable-ScheduledTask
   ```

   Uzak Masaüstü'nde Network Level Authentication açık kalır. Windows firewall'unun kendisini açık
   bırakın; panel VM'in dışında Proxmox firewall'uyla filtreler. Ping'e cevap vermek bir tercihtir.
   `powercfg /hibernate off`, Windows 10 ve 11'de **Fast Startup**'ı da kapatır; o açıkken kapatma
   aslında bir hazırda bekletmedir ve böyle "kapatılmış" bir şablon temiz kapanmış olmaz.

4. **Yalnızca Windows 10 ve 11:**

   ```powershell
   # Device encryption must be off: clones would share the key, the panel could not grow C:,
   # and Sysprep refuses an encrypted drive
   manage-bde -status C:
   manage-bde -off C:      # only if it says the volume is encrypted; wait until it is fully decrypted
   # Stop Windows installing promoted Store apps for each user (Enterprise and Education honour it)
   New-Item 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\CloudContent' -Force | Out-Null
   Set-ItemProperty 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\CloudContent' -Name DisableWindowsConsumerFeatures -Value 1
   ```

5. VM çalışırken node'un kabuğundan **agent'ı kontrol edin**:

   ```bash
   qm guest cmd <vmid> ping
   qm guest exec <vmid> -- powershell -NoProfile -Command '$PSVersionTable.PSVersion'
   ```

   Panelin script'leri ve Windows uygulamaları Windows PowerShell 5.1 ister; tablodaki her sürümde
   vardır.

### 5. Administrator hesabı

Panel, parola sıfırlaması ve Cloudbase-Init hep **`Administrator`** adlı hesabı kullanır: SID'i
`-500` ile biten yerleşik hesap. Kontrol edin:

```powershell
Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Format-Table Name, Enabled
```

- **Adı `Administrator` olmalıdır.** Bazı görüntü dilleri ona başka bir ad verir (Fransızca
  *Administrateur*, İspanyolca *Administrador*, …). Yeniden adlandırın ya da İngilizce medyadan
  kurun: `Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Rename-LocalUser -NewName Administrator`.
- **Her klonda etkin olmalıdır.** Windows Server onu açar. Windows 10 ve 11 onu kapatır, Sysprep de
  audit modunu bitirirken yeniden kapatır. Bu yüzden şablon, her klonun ilk açılışında onu bir
  Cloudbase-Init *yerel script*'iyle açar (`LocalScriptsPlugin` tarafından SYSTEM olarak, parola
  ayarlandıktan sonra çalıştırılır). Cloudbase-Init kurulunca (6. adım) bunu
  `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\enable-administrator.ps1` olarak
  kaydedin. Sunucularda zararsızdır, her yerde kullanın:

  ```powershell
  # Proxmotion: the panel logs customers in as the built-in Administrator
  Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Enable-LocalUser
  ```

### 6. Cloudbase-Init'i kurun

`https://cloudbase.it/cloudbase-init/` adresinden indirip kurun: kullanıcı adı `Administrator`,
*Run Cloudbase-Init service as LocalSystem* işaretli. Sonra 5. adımdaki yerel script'i ekleyin.

Kurulumun son sayfası Sysprep'i çalıştırmayı önerir. **Yol A:** *Finish*'e henüz basmayın, sonraki
adımlar ona ihtiyaç duyar. **Yol B:** iki kutuyu da işaretsiz bırakıp *Finish*'e basın.

Yapılandırması `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` içindedir:

| Dosya | Ne zaman kullanılır |
|---|---|
| `cloudbase-init.conf` | **servis** tarafından, her açılışta (iki yolda da) |
| `cloudbase-init-unattend.conf` | Windows kurulumunun *specialize* aşamasında (yalnızca Yol A) |
| `Unattend.xml` | Sysprep'in Windows kurulumuna verdiği cevap dosyası (yalnızca Yol A) |

## Yol A: Sysprep'li

### A1. Cloudbase-Init'i ayarlayın

Amaç: bir klonun ihtiyaç duyduğu her şey, Windows'un zaten yaptığı yeniden başlatmadan önce
**specialize aşamasında** ayarlansın, başka yeniden başlatma gerekmesin; servis sonra yalnızca
parolayı ve ağı ayarlasın, yerel script'i çalıştırsın.

`cloudbase-init-unattend.conf`, specialize aşamasında çalışır. Bilgisayar adını orada ayarlar
(Windows'un kendi yeniden başlatmasıyla geçerli olur), `C:`'yi genişletir ve kendiliğinden asla
yeniden başlatmaz:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init-unattend.log
plugins=cloudbaseinit.plugins.common.sethostname.SetHostNamePlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=false
stop_service_on_exit=false

[config_drive]
types=iso
locations=cdrom
```

`cloudbase-init.conf`, servis, her açılışta. Önce parola, sonra ağ:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init.log
local_scripts_path=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`Unattend.xml`: şu kısımları değiştirin, gerisini Cloudbase-Init'in kurduğu gibi bırakın.

1. **Tüm sürümler.** `specialize` aşamasında Cloudbase-Init komutu yeniden başlatma istememelidir.
   `&amp;&amp; exit 1 || exit 2`'yi `&amp; exit 0` ile, `OnRequest`'i `Never` ile değiştirin
   (`&amp;`'ı olduğu gibi bırakın: çıplak bir `&` XML'i bozar):

   ```xml
   <RunSynchronousCommand wcm:action="add">
     <Order>1</Order>
     <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
     <Description>Run Cloudbase-Init to set the hostname</Description>
     <WillReboot>Never</WillReboot>
   </RunSynchronousCommand>
   ```

2. **Windows 10 ve 11.** OOBE bir soruda durmamalıdır: bir VPS'te ona kimse cevap vermez.
   `oobeSystem` aşamasındaki `Microsoft-Windows-Shell-Setup` bileşenini aşağıdakiyle değiştirin ve
   yanına `International-Core` bileşenini ekleyin. Dil değerleri bölge ve klavye ekranlarını atlar
   (kendinizinkini kullanın, ör. `tr-TR`); `AdministratorPassword` OOBE'nin hesap oluşturmayı
   atlamasını sağlar ve ilk açılışta Cloudbase-Init tarafından değiştirilir (gerçek bir parola değil,
   uzun rastgele bir değer kullanın):

   ```xml
   <settings pass="oobeSystem">
     <component name="Microsoft-Windows-International-Core" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <InputLocale>en-US</InputLocale>
       <SystemLocale>en-US</SystemLocale>
       <UILanguage>en-US</UILanguage>
       <UserLocale>en-US</UserLocale>
     </component>
     <component name="Microsoft-Windows-Shell-Setup" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <OOBE>
         <HideEULAPage>true</HideEULAPage>
         <HideOEMRegistrationScreen>true</HideOEMRegistrationScreen>
         <HideOnlineAccountScreens>true</HideOnlineAccountScreens>
         <HideWirelessSetupInOOBE>true</HideWirelessSetupInOOBE>
         <HideLocalAccountScreen>true</HideLocalAccountScreen>
         <ProtectYourPC>3</ProtectYourPC>
       </OOBE>
       <UserAccounts>
         <AdministratorPassword>
           <Value>a-long-random-value-replaced-at-first-boot</Value>
           <PlainText>true</PlainText>
         </AdministratorPassword>
       </UserAccounts>
     </component>
   </settings>
   ```

3. *İsteğe bağlı, tüm sürümler:* kurulu sürücüleri koruyan bir `generalize` aşaması ekleyin (doğrudan
   `<unattend>` içine, diğer `<settings>`'lerin yanına); ilk açılış her aygıtı yeniden algılamaz.
   Microsoft buna yalnızca her hedef makine referans makineyle birebir aynı donanıma sahipse izin
   verir: yalnızca her sunucu şablonun sanal donanımını (aynı disk veri yolu, ağ modeli ve makine
   türü) koruduğu sürece kullanın, emin değilseniz eklemeyin:

   ```xml
   <settings pass="generalize">
     <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
     </component>
   </settings>
   ```

Her ayarın neden orada olduğu: [Yapılandırma dosyalarının açıklaması](#yapılandırma-dosyalarının-açıklaması).

### A2. Sysprep'in işini mümkün kılın

Bir Store uygulaması bir kullanıcı için güncellenip tüm kullanıcılar için provision edilmemişse
Sysprep genelleştirmeyi reddeder. Böyle uygulamaların kullanıcı kopyalarını kaldırın; provision
edilmiş sürümler kalır ve yeni kullanıcılara kurulur.

- **Sunucular** (yalnızca 2022 ve 2025'te Edge; Server 2016 ve 2019'da yok):

  ```powershell
  Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
  ```

- **Windows 10 ve 11**, birçok uygulamanın kendini güncellediği yerler: kurulu olup provision
  edilmemiş her paketi kaldırın (diğer uygulamaların ihtiyaç duyduğu framework'ler gitmeyi reddeder,
  bu normaldir):

  ```powershell
  $provisioned = (Get-AppxProvisionedPackage -Online).PackageName
  Get-AppxPackage -AllUsers |
      Where-Object { -not $_.NonRemovable -and $provisioned -notcontains $_.PackageFullName } |
      ForEach-Object { Remove-AppxPackage -Package $_.PackageFullName -AllUsers -ErrorAction SilentlyContinue }
  ```

Sysprep yine durursa `C:\Windows\System32\Sysprep\Panther\setuperr.log` paketin adını verir.

### A3. Küçültün

Şablon diskinde hâlâ kullanılan her GB, her yeni sunucu için kopyalanır (full clone):

```powershell
# Superseded update files (afterwards installed updates can no longer be uninstalled)
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\*", 'C:\Windows\Temp\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\Windows\SoftwareDistribution\Download\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose
```

### A4. Genelleştirin

Cloudbase-Init kurulumuna dönün, **Run Sysprep to create a generalized image** ve **Shutdown when
Sysprep terminates**'i işaretleyip *Finish*'e basın. (Kurulum programı olmadan
[Şablonu güncellemek](#şablonu-güncellemek) bölümündeki komutu çalıştırın.) VM kendini genelleştirir
ve kapanır. Windows 10 ve 11'de bu, audit modunu da bitirir.

**Onu bir daha başlatmayın**: ilk açılışı specialize aşamasını ve OOBE'yi çalıştırır, şablon artık
genelleştirilmiş olmaz. [VM'i şablona çevirin](#vmi-şablona-çevirin) ile devam edin.

## Yol B: Sysprep'siz

Windows Server 2025 ve Windows 11 için değil (bkz. [karşılaştırma](#klonları-benzersiz-yapmak-sysprepli-veya-sysprepsiz)).

### B1. Cloudbase-Init'i ayarlayın

Specialize aşaması olmadan klonun ilk açılışında her şeyi **servis** yapar: parola, ağ, `C:`'nin
genişletilmesi, yerel script ve bilgisayar adı. Windows yeni bir bilgisayar adını yalnızca yeniden
başlatmayla uygular; bu yüzden onu ayarlayan plugin (`UserDataPlugin`) **en sona** gelir ve servis
yeniden başlatabilir (`allow_reboot=true`); yeniden başlatmadan sonra tamamlanmış plugin'ler atlanır
ve servis durur.

`cloudbase-init.conf`'u şununla değiştirin:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init.log
local_scripts_path=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`cloudbase-init-unattend.conf` ve `Unattend.xml` bu yolda kullanılmaz; olduğu gibi bırakın.

`cloudbase-init` servisinin başlangıç türünü **Automatic** bırakın, *Automatic (Delayed Start)*
değil: panel, servis yeni sunucu için plugin'lerini kaydedene kadar bekler; geç başlayan bir servis
yalnızca her sunucunun daha geç hazır olmasına yol açar.

### B2. Sunucu başına farklı olması gerekenleri silin

**Windows 10:** şablonda 3. adımdaki geçici hesap kalmamalıdır. Yerleşik Administrator'ı açın, ona
bir parola verin, oturumu kapatın, `Administrator` olarak girin ve diğer hesabı silin:

```powershell
Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Enable-LocalUser
net user Administrator *          # asks for a temporary password
# sign out, sign in as Administrator, then:
Remove-LocalUser -Name setup      # its profile folder under C:\Users can be deleted too
```

**Tüm sürümler:** Sysprep'in sıfırlayacağı ve bir sunucu için önemli olan değerler bunlardır.
Yönetici olarak açılmış PowerShell'de:

```powershell
# Remote Desktop certificate: Windows creates a new one when the service starts on a clone
Remove-Item -Path 'Cert:\LocalMachine\Remote Desktop\*' -Force
# Windows Update client ID: WSUS and update reports would see every server as one
Stop-Service wuauserv
Remove-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate' -Name SusClientId, SusClientIdValidation -ErrorAction SilentlyContinue
# Cloudbase-Init's memory of instances it has seen, and its logs
Remove-Item 'HKLM:\SOFTWARE\Cloudbase Solutions\Cloudbase-Init\*' -Recurse -ErrorAction SilentlyContinue
Remove-Item 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\*' -ErrorAction SilentlyContinue
# Event logs of the build
wevtutil el | ForEach-Object { wevtutil cl "$_" 2>$null }
```

Ortak kalan ve Sysprep olmadan düzeltilemeyen: makine SID'i ve KMS istemci makine kimliği.

Şablonun Administrator parolası, Cloudbase-Init onu değiştirene kadar klonda kalır; ağ
yapılandırılmadan önce `SetUserPasswordPlugin`'in çalışmasının nedeni budur. Yine de şablon için
uzun, rastgele bir parola kullanın.

### B3. Küçültün ve kapatın

[A3](#a3-küçültün)'teki komutları çalıştırın (Store uygulaması adımı gerekmez: Sysprep yok), sonra
VM'i normal şekilde kapatın (4. adımdan beri Fast Startup kapalı, bu gerçek bir kapatmadır):

```powershell
Stop-Computer
```

Dönüştürmeden önce **onu bir daha başlatmayın**: Cloudbase-Init ve Windows yeniden yeni değerler
yazar ve B2 tekrarlanmalıdır. [VM'i şablona çevirin](#vmi-şablona-çevirin) ile devam edin.

## VM'i şablona çevirin

VM kapalıyken:

1. *Hardware*: **iki** CD/DVD sürücüsünü de kaldırın.
2. *Hardware → Add → CloudInit Drive*, diskle aynı storage'da. *Cloud-Init* sekmesini boş bırakın:
   panel her sunucu için doldurur. (Sürücüyü, VM şablon olarak hâlâ açılabilecekken asla eklemeyin:
   Cloudbase-Init onu uygular.)
3. *Options*: *Boot Order*'da yalnızca disk (`scsi0`), *QEMU Guest Agent* açık.
4. *More → Convert to template*.

**Local** storage'lı bir cluster'da şablon, sunucu barındıran her node'da gerekir (*Clone → Target
node*, mod *Full clone* ile oraya klonlayıp klonu dönüştürün). **Shared** storage'da bir tane yeter.
Bir **linked clone** ayrıca şablonun, sunucunun diskinin gideceği storage'ın ta kendisinde olmasını
ister.

## Panele ekleyin ve test edin

1. Panelin API token'ı bir resource pool'la sınırlıysa şablonu o pool'a ekleyin (*Datacenter →
   Permissions → Pools → `<pool>` → Members*).
2. *Admin → Cluster'lar*: senkronize edin.
3. *Admin → OS şablonları*: *İşletim sistemleri* altında sistemi **Windows** türüyle (ör. sürüm 2022
   ile "Windows Server" ya da sürüm 24H2 ile "Windows 11") ekleyin, sonra o sürümle OS şablonunu
   ekleyip Proxmox şablonunu ona eşleyin. Panelin sunucularını Windows olarak ele almasını sağlayan
   **Windows** türüdür. *Klonlama yöntemi*'ni seçin (bkz.
   [Ne kadar hızlı](#yeni-bir-sunucu-ne-kadar-hızlı-hazır-olabilir)). Onun için paketlere en az
   şablonun disk boyutunu ve Windows 11 için 4 GiB bellek ile 2 çekirdek verin.
4. Yeni sürümü yalnızca üzerinde çalışan uygulamalar için işaretleyin (*Admin → Uygulamalar →
   Sistemleri seç*): dahili Windows recipe'leri Windows Server için yazıldı.
5. **İki** test sunucusu oluşturun ve kontrol edin:

| Kontrol | Beklenen |
|---|---|
| Görevin *guest agent'ı bekle* adımı | konsol oturum açma ekranını gösterdiği sıralarda biter (arada OOBE sorusu yok); sunucu *Aktif* olur |
| Panelin gösterdiği parolayla `Administrator` olarak **Uzak Masaüstü** | girer; şablonun parolası girmez |
| `hostname`, `ipconfig`, `Get-Volume C` | sunucunun adı, panelin adresi, paketin disk boyutu |
| Yeniden başlatmalar: `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 \| Format-List TimeCreated, Message` | Yol A: `winlogon.exe`'den bir tane, Cloudbase-Init'ten (`python.exe`) hiç. Yol B: bilgisayar adı için Cloudbase-Init'ten bir tane |
| Yalnızca Yol A: iki sunucuda `whoami /user` | SID'in son `-`'den önceki kısmı farklı |
| Yol B: iki sunucuda Uzak Masaüstü sertifikasının parmak izi (`Get-ChildItem 'Cert:\LocalMachine\Remote Desktop'`) | farklı |
| Sunucu sayfasında *Parolayı sıfırla* | yeni parola Uzak Masaüstü'nde çalışır |

## Yapılandırma dosyalarının açıklaması

- **Servisin plugin'leri.** Varsayılan `CreateUserPlugin` yalnızca var olan Administrator'ın
  parolasını yeniden ayarlıyor ve 10 sn sürüyordu; `SetUserPasswordPlugin` onu metadata'dan
  (`admin_pass`) saniyenin altında ayarlar. Hesabın `Administrator` adıyla var olmasını ister
  ([5. adım](#5-administrator-hesabı)).
- **Ağdan önce parola.** `SetUserPasswordPlugin` çalışana kadar şablonun parolası geçerlidir; onu
  `NetworkConfigPlugin`'den önce çalıştırmak, bu durum sürerken sunucuya adresinden ulaşılamaması
  demektir.
- **`LocalScriptsPlugin`** `enable-administrator.ps1`'i SYSTEM olarak çalıştırır: Windows 10 ve 11'de
  Sysprep'in kapattığı hesap, panel sunucuyu hazır saymadan önce açılır (servis henüz durmamıştır).
- **`ExtendVolumesPlugin`** `C:`'yi panelin oluşturduğu diske büyütür: Yol A'da specialize
  aşamasında, Yol B'de serviste. Sonraki disk yükseltmelerini panelin `disk.ps1`'i genişletir.
- **`UserDataPlugin`** bilgisayar adını ayarlar. Proxmox hostname'i yalnızca user-data'ya
  (`#cloud-config`, `hostname: …`) yazar; `meta_data.json`'da yoktur, bu yüzden `SetHostNamePlugin`
  tek başına ad bulamaz. Proxmox'un user-data'sından Windows'ta yalnızca `hostname` çalışır; `user`,
  `password`, `chpasswd`, `fqdn`, `disable_root` ve `manage_etc_hosts` *not supported* olarak
  log'lanır, yani hiçbir şey kaybolmaz. Windows bir adı bir sonraki yeniden başlatmada uygular: Yol
  A'da bu specialize aşamasının yeniden başlatmasıdır, Yol B'de servisin kendi yeniden başlatması;
  orada son plugin olmasının nedeni budur.
- **`metadata_services`** yalnızca `ConfigDriveService` ile, Cloudbase-Init'in Proxmox'ta yalnızca
  zaman aşımına uğrayan ağ metadata servislerini (EC2, OpenStack HTTP) denemesini önler (yaklaşık
  iki dakika).
- **`[config_drive]`**: Proxmox'un CloudInit drive'ı bir CD sürücüsündeki ISO'dur; `hdd` üzerinde
  `vfat` yalnızca *"mtools_path" needs to be provided* log'lar. Bu seçenekler eskimiş
  `config_drive_cdrom`, `config_drive_vfat` ve `config_drive_raw_hdd`'nin yerini alır.
- **`allow_reboot`**: unattend dosyasında `false`, çünkü specialize aşamasındaki bir yeniden
  başlatma Sysprep'i bozar; serviste `true`, Yol B'de bilgisayar adı için yeniden başlatır.
- **`stop_service_on_exit=true`**: servis işini bitirince durur; panel, kaydettiği plugin'lerin
  yanında bunu da bekler (çalışmayı sürdüren bir servis beklemeyi ancak 15 dakika sonra bitirir).
- **`plugins`**, panelin servisin işini bitirdiğini anlamak için okuduğu satırdır da:
  `cloudbase-init.conf`'ta listelenen her plugin, sunucunun instance kimliği için tamamlandı olarak
  kaydedilmiş olmalıdır. Satırı tek satırda tutun; o satır yoksa panel kaydedilmiş herhangi bir
  plugin'i kabul eder, yeter ki hiçbiri sonraki açılışı beklemesin.
- **`WillReboot` `Never` ve `& exit 0`** (Yol A): Cloudbase-Init'in kendi `Unattend.xml`'i
  `&& exit 1 || exit 2` ve `OnRequest` ile biter; çıkış kodu 1, Cloudbase-Init başarılı olsa bile
  Windows kurulumundan ek bir yeniden başlatma ister. Specialize aşamasında başarısız olan bir
  çalıştırma artık tekrarlanmaz; yalnızca bilgisayar adı atlanır (parola ve ağ servisten gelir).
  Log'u `cloudbase-init-unattend.log`'dur.
- **Windows 10 ve 11 için `oobeSystem` aşaması** (Yol A): Windows Server'ın OOBE'si parola
  bilinince cevap beklemez, istemcininki bölge, klavye, ağ, hesap ve gizlilik ayarları sorar. Her
  `Hide…` ayarı bir ekranı kaldırır, `ProtectYourPC` 3 gizlilik ekranını en kısıtlayıcı seçimle
  cevaplar.
- **`PersistAllDeviceInstalls`** (isteğe bağlı, Yol A) şablonun aygıtlarını korur; sanal donanımı
  değiştirilmedikçe bunlar her klona uyar ve "Getting ready"yi kısaltmalıdır (*henüz ölçülmedi*).
  Çekirdek sayısı, bellek boyutu ve MAC adresi bu anlamda donanım farkı değildir; farklı bir disk veri
  yolu veya ağ modeli farktır.
- **`LocalScripts\`** içindeki script'ler ilk açılışta çalışır ve sonrasındaki her şeyi geciktirir;
  log her birinin ne kadar sürdüğünü gösterir (`Script "…" ended with exit code`). Orada sabit
  beklemelerden kaçının.

## Yeni bir sunucu ne kadar hızlı hazır olabilir

Windows Server 2022, 2 vCPU, 4 GiB, LVM-thin üzerinde ölçüldü (2026-10-20), Yol A:

| Aşama | Süre | Neye bağlı |
|---|---|---|
| Şablonun full clone'u | 2:00 | Şablon diskinin dolu kısmı ve storage'ın hızı. **Linked clone** saniyeler sürer |
| İlk açılış: specialize aşaması ("Getting ready"), ardından tek yeniden başlatma | 1:01 | vCPU sayısı; aygıt kurulumu |
| İkinci açılıştan OOBE'nin bitmesine kadar | 0:20 | vCPU sayısı |
| Cloudbase-Init servisi (ağ, parola) | 0:26 | 10 sn'si bu rehberin çıkardığı `CreateUserPlugin`'di (o zamandan beri ölçülmedi) |
| Panelin kurulumun bittiğini görmesi | birkaç saniye | Her 5 sn'de bir kontrol eder |

Diğer sürümler ölçülmedi. Windows 10 ve 11'in daha uzun sürmesini bekleyin: daha büyük bir şablon
(Windows 11 64 GiB'tan başlar) full clone'u yavaşlatır ve OOBE'leri daha çok iş yapar. Yol B
specialize aşamasını ve OOBE'yi atlar (yukarıdakinin yaklaşık 1:20'si) ama bilgisayar adı için bir
yeniden başlatma ekler.

Kazandıranlar, büyükten küçüğe:

1. OS şablonunda **Klonlama yöntemi *Linked clone*** ([ADR 0027](../../adr/0027-linked-clones.md)):
   yaklaşık 2 dakika daha az. Sunucular bu durumda şablona bağımlı olur; önce ADR'yi okuyun.
2. **Küçük bir şablon** (A3): full clone, thin bir diskin yalnızca dolu bloklarını kopyalar.
3. Pakette **daha çok vCPU**: kurulum aşamaları çoğunlukla işlemci işidir.
4. Sürümün izin verdiği yerde, bedelleriyle birlikte Yol A yerine **Yol B**.
5. **Gerekenden fazla yeniden başlatma yok** ve **gereksiz Cloudbase-Init plugin'i yok**.

## Şablonu güncellemek

Güvenlik güncellemeleri için şablonu her ay yenileyin.

**Yol A:**

1. Şablonu *Clone* edin (mod **Full clone**), klonu başlatıp konsolda girin. İlk açılış kurulumunu
   bir kez çalıştırır.
2. Hiçbiri beklemeyene kadar güncellemeleri kurup yeniden başlatın, sonra A2 ve A3'ü çalıştırın.
3. Cloudbase-Init'in cevap dosyasıyla genelleştirin ve kapanmasına izin verin:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

**Yol B:**

1. Şablonu *Clone* edin (mod **Full clone**). Klonun *Cloud-Init* sekmesinde geçici bir *Password*
   belirleyin, başlatın ve konsolda girin (Cloudbase-Init parolayı uygular ve ad için bir kez yeniden
   başlatır).
2. Hiçbiri beklemeyene kadar güncellemeleri kurup yeniden başlatın, sonra B2'yi (tüm sürümler kısmı)
   ve B3'ü çalıştırın.

**İkisi de:** [VM'i şablona çevirin](#vmi-şablona-çevirin) adımını yeni, tarihli bir adla yapın,
cluster'ı senkronize edin ve OS şablonunu yeni şablona eşleyin. Eski şablonu, onu kullanan hiçbir şey
kalmayınca silin. Full clone'lar ona bağlı değildir; **linked clone**'lar temel diskini kullandıkça
Proxmox onu silmeyi reddeder, o sunucular reinstall edilene ya da sonlandırılana kadar tutun.

## Panel Windows için ne yapar

İşletim sisteminin **Windows** türü, sunucu ve istemci sürümlerinde aynı şekilde OS şablonunun
platformunu `windows` yapar. Platform şunlara karar verir:

| İşlem | Windows'ta nasıl çalışır |
|---|---|
| İlk açılış | Config drive parolayı (`admin_pass`), ağ ayarlarını ve hostname'i (user-data'da) taşır. Cloudbase-Init bunları uygular |
| Ek IP adresleri, birincil adres | QEMU guest agent üzerinden çalıştırılan varsayılan `addresses.ps1` guest script'i (ADR 0010, 0011). Adresleri her açılışta yeniden uygulayan bir **"Proxmotion addresses" zamanlanmış görevi** kaydeder |
| Yükseltmeyle büyüyen disk | Varsayılan `disk.ps1`, `C:` partition'ını genişletir |
| Parola sıfırlama | Guest agent **`Administrator`**'ın parolasını ayarlar (ya da şablonun kendi `password_reset` script'i) |
| Hostname değişikliği | Varsayılan `hostname.ps1` bilgisayarı hostname'in ilk etiketine (en çok 15 karakter) yeniden adlandırır. Windows bunu bir sonraki yeniden başlatmada uygular: istenirse panel sunucuyu yeniden başlatır, yoksa *yeniden başlatma gerekli* gösterir (ADR 0012) |
| Uygulamalar | Yalnızca Windows uygulamaları (IIS, SQL Server Express, Chocolatey ve `platform: windows` olan recipe'ler); `run.ps1` bunları **`\Proxmotion\` altında bir zamanlanmış görev** olarak SYSTEM ile çalıştırır (ADR 0029). Windows PowerShell 5.1 gerekir |

Script'ler şablon başına *OS şablonları → Script'ler* altında değiştirilebilir.

**Yeni bir sunucu ne zaman hazırdır.** Guest agent, Windows kullanılabilir olmadan çok önce,
specialize aşamasında zaten cevap verir. Bu yüzden yeni veya reinstall edilmiş bir Windows sunucusu
için görevin *guest agent'ı bekle* adımı, birkaç saniyede bir agent üzerinden kontrol ederek şunlar
olana kadar sürer:

- `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` altındaki `ImageState`
  `IMAGE_STATE_COMPLETE` olur (Yol B'de zaten öyledir; bu değeri olmayan bir şablon tamamlanmış
  sayılır),
- hiçbir yeni bilgisayar adı yeniden başlatma beklemez
  (`HKLM\SYSTEM\CurrentControlSet\Control\ComputerName` altındaki `ComputerName` ile
  `ActiveComputerName` aynıdır),
- `cloudbase-init` servisi artık çalışmıyordur (`Stopped` ya da kurulu değil) ve
- Cloudbase-Init, servisin yapılandırmasındaki her plugin'i (servisin çalıştığı
  `cloudbase-init.conf`'un `plugins` satırı) `HKLM\SOFTWARE\Cloudbase Solutions\Cloudbase-Init\<instance kimliği>\Plugins`
  altında tamamlandı (`1`) olarak kaydetmiştir; instance kimliği config drive'daki
  `openstack\latest\meta_data.json`'un `uuid`'sidir. Specialize aşamasının plugin'leri de oraya
  kaydedilir ama sayılmaz.

Servisin durmuş olması tek başına yetmez: başlamadan önce de, çalışması bittikten sonra istediği
yeniden başlatmaya kadar da, yeni bilgisayar adı henüz kullanılmazken, aynı görünür. Böyle bir anda
çalışan içeriden kontroller (Server 2022'de başlatmadan 51 saniye, Windows 10'da yaklaşık dört dakika sonra) `WIN-…` ya da `DESKTOP-…`
adını ve henüz büyütülmemiş bir `C:` bildirdi; sunucu adını daha sonra almıştı.

Aradaki yeniden başlatmalar beklenir. Sunucu ancak o zaman *Aktif* olur ve adres script'i ile
şablonun kendi script'i çalışır. Agent 10 dakika sonra hiç cevap vermediyse (agent'sız bir şablon)
adım Linux'taki gibi bu notla biter; kurulum 15 dakika sonra bitmediyse adım, son kontrolün hâlâ
neyi beklediğiyle birlikte *Windows setup did not finish within 15 minutes* notunu düşer (örneğin
*Cloudbase-Init has not finished NetworkConfigPlugin*: başarısız olan bir plugin hiçbir zaman
tamamlandı olarak kaydedilmez; `cloudbase-init.log`'a bakın) ve görev devam eder. Bir yeniden başlatma yine de bir
script'i keserse panel agent'ı bekleyip script'i yeniden çalıştırır (en çok üç kez) ve Windows
script'leri ağa dokunmadan önce Cloudbase-Init'i bekler.

**Bir Yol A sunucusu neden bir kez yeniden başlar.** Windows kurulumu specialize aşamasını OOBE'den
önce her zaman bir yeniden başlatmayla bitirir (`C:\Windows\Panther\UnattendGC\setupact.log`:
*Reboot required after setup.exe and PostSysprep commands, before launching OOBE*). Cloudbase-Init'in
varsayılanları iki tane daha ekler; A1'deki yapılandırma bunları kaldırır:

| Yeniden başlatma | Neden | Çözüm |
|---|---|---|
| Cloudbase-Init servisi (1074 olayında `python.exe`) | Servisteki `UserDataPlugin` adı ayarlar ve yeniden başlatır (`allow_reboot=true`) | `UserDataPlugin`'i specialize aşamasında çalıştırın |
| Specialize aşamasının ortasında `winlogon.exe` (`C:\Windows\Panther\setupact.log`'da `Unattend action requested immediate reboot and recall`) | `Unattend.xml`'deki `<WillReboot>OnRequest</WillReboot>` ve `&& exit 1 \|\| exit 2` | `<WillReboot>Never</WillReboot>` ve `& exit 0` |

## Sorun giderme

| Gördüğünüz | Anlamı / ne yapmalı |
|---|---|
| Windows 11 kurulumu: *This PC can't run Windows 11* | TPM 2.0 yok, Secure Boot kapalı (pre-enrolled keys'siz EFI diski), 4 GiB, 64 GiB veya 2 çekirdekten az, ya da x86-64-v2'siz bir CPU türü |
| Server 2025 veya Windows 11 açılmıyor ya da erken duruyor | CPU türü `kvm64`/`qemu64`: varsayılan `x86-64-v2-AES`'i kullanın |
| Windows 10/11: yeni bir sunucu konsolda bir OOBE ekranında duruyor | A1'deki `oobeSystem` aşaması eksik ya da yarım; gösterilen ekran hangi ayarın eksik olduğunu söyler |
| Uzak Masaüstü bağlanmıyor | Uzak Masaüstü varsayılan olarak kapalıdır: 4. adım. Windows Home'da Uzak Masaüstü sunucusu yoktur. Panelin adresinin ayarlandığını da kontrol edin (`ipconfig`) |
| Uzak Masaüstü: `Administrator` için parola reddediliyor | Hesap kapalı (Windows 10/11, 5. adım: yerel script) ya da adı yerelleştirilmiş |
| Parola ayarlanmamış (`cloudbase-init.log`'da `Using admin_pass metadata user password` yok) | VM'in bir CloudInit drive'ı olmalı ve OS türü Windows olmalı; hesabın adı `Administrator` olmalı |
| Yol A: iki yeniden başlatma, ikisi de `winlogon.exe` | `Unattend.xml` Cloudbase-Init'ten sonra hâlâ yeniden başlatma istiyor: A1 |
| Yol A: `python.exe` (Cloudbase-Init) tarafından bir yeniden başlatma | `UserDataPlugin`, `cloudbase-init-unattend.conf` yerine `cloudbase-init.conf`'ta |
| Sysprep duruyor: *Package … was installed for a user, but not provisioned for all users* | A2; `C:\Windows\System32\Sysprep\Panther\setuperr.log` paketin adını verir |
| Sysprep Windows 10/11'de BitLocker yüzünden duruyor | Cihaz şifrelemesi açık: 4. adım |
| Yol B: bilgisayar adı şablonunki olarak kalıyor | `UserDataPlugin` eksik ya da `allow_reboot=false`: B1. `cloudbase-init.log` `Changing hostname` göstermeli |
| Yol B: şablonun parolası yeni bir sunucuda çalışıyor | `SetUserPasswordPlugin` çalışmamış: Cloudbase-Init bu instance'ı zaten işlediğini sanıyor (B2'de registry temizlenmemiş) ya da VM'in OS türü Windows değil |
| Yol B: Uzak Masaüstü her sunucuda aynı sertifika için uyarıyor | Sertifika B2'de silinmemiş |
| Yol B: her sunucunun *Aktif* olması biraz daha uzun sürüyor | Servis geç başlıyor (gecikmeli başlatma): B1 |
| "Getting ready" uzun sürüyor, *Working on updates* | Şablonda bekleyen güncellemeler vardı |
| Hostname hâlâ `WIN-…` veya `DESKTOP-…` (Yol A) | `cloudbase-init-unattend.log` `Changing hostname` göstermeli |
| Görev 15 dakika sürüyor ve *Windows setup did not finish* notunu düşüyor | Not, son kontrolün neyi beklediğini söyler. *the cloudbase-init service is Running*: `stop_service_on_exit=true` eksik. *Cloudbase-Init has not finished …*: o plugin'ler başarısız oldu (`cloudbase-init.log`) ya da config drive'ın `config-2` etiketi yok. *Cloudbase-Init has not run for this server yet*: şablonda `plugins` satırı yok ve servis hiçbir şey kaydetmedi. *Windows setup reports …*: kurulum takıldı, konsola bakın |
| *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Script çalışırken Windows üç kez yeniden başladı. Zamanlanmış görev adresleri bir sonraki açılışta uygular; ya da Script'ler sayfasında *Sunucuda çalıştır* |
| *Get-NetAdapter : Critical error* | Windows hâlâ kurulumdaydı; güncel script'ler bunu bekler. Varsayılanın yerine geçtiyse şablonun override'ını güncelleyin |

Yeni bir sunucunun zamanı nereye gitti (yeni sunucuda, yönetici olarak PowerShell):

```powershell
Get-WinEvent -FilterHashtable @{LogName='System'; Id=12} -MaxEvents 3 | Format-Table TimeCreated
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init*.log' | Select-String 'Executing plugin|Plugins execution done|ended with|Rebooting'
Select-String -Path C:\Windows\Panther\UnattendGC\setupact.log -Pattern 'ImageState'
```

12. olay her açılış, 1074 her yeniden başlatma ve onu kimin istediği; Cloudbase-Init satırları her
plugin'in ne kadar sürdüğünü, `ImageState` kurulumun ne zaman bittiğini gösterir.

## Henüz doğrulanmayanlar

- **Panelle yalnızca Yol A ile Windows Server 2022 kullanıldı** (2026-10-20): `Unattend.xml`
  değişikliğiyle tek yeniden başlatma ve sunucunun Cloudbase-Init servisi durduktan birkaç saniye
  sonra *Aktif* olması. Denenmedi: Server 2016, 2019 ve 2025, Windows 10 ve 11, Yol B, parolanın
  önce geldiği plugin sırası, Administrator'ı açan yerel script.
- **Özellikle Windows 10 ve 11:** audit modu ile A1'deki `oobeSystem` aşamasının hiçbir OOBE ekranı
  bırakmadığı, `HideLocalAccountScreen` ile `AdministratorPassword`'ün ek bir hesap oluşturmadığı,
  A2'deki Store paketi temizliğinin Sysprep için yettiği, linked clone'larda TPM state ve dahili
  Windows recipe'lerinin istemci sürümlerinde çalışması.
- **2026-10-27'den beri hazır olma kontrolü:** config drive'dan okunan instance kimliği altındaki
  plugin durumları, servis komut satırının `--config-file`'ı ve yeniden başlatmaya kadar
  `ActiveComputerName` (önceki kontrol yalnızca servise bakıyordu; servis Server 2022 ve Windows
  10'da çok erken durmuş görünüyordu). Cloudbase-Init'in kaynağından alındı, yalnızca örnek
  değerlerle denendi.
- **Yol B:** bilgisayar adının servis tarafından yeniden başlatmasıyla ayarlanması, Uzak Masaüstü
  sertifikasının ve Windows Update istemci kimliğinin silinmesi ve Cloudbase-Init'in registry
  anahtarının temizlenmesi. Aynı SID'li makineler arasındaki kimlik doğrulama hataları Microsoft'un
  sürüm notlarından alındı, tarafımızdan denenmedi.
- Yaşam döngüsü tarihleri ve QMTH lisans notu Microsoft'un kamuya açık belgelerinden alındı; onlara
  güvenmeden önce kontrol edin.
- Ölçülmedi: `PersistAllDeviceInstalls`, `CreateUserPlugin`'siz servis, küçültme komutları, bir
  Windows şablonunun linked clone'ları.
- Windows uygulamaları (ADR 0029) yalnızca panelin sahte Proxmox'una karşı çalıştı. Windows
  varsayılan script'leri tek bir Windows Server imajında çalıştı (2026-10-17): reinstall sonrası ek
  adresler.
