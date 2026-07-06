# Decoder
## О проекте
Веб сервис, обеспечивающий процесс шифрования для модулей приложения WebAPPs
## Структура каталогов и их назначение
`decoderapp` Каталог исходных файлов проекта, с которым работает контейнер PHP  
`nginx` Каталог инициализации контейнера веб-сервера NGINX  
`├─ certs` Каталог, в котором следует разместить сертификаты веб-сервера  
`├─ conf.d` Каталог конфигурация NGINX  
`└─ logs` Журналы /var/log  
`php` Каталог инициализации контейнера PHPFPM  
`├─ ./Dockerfile` Данный контейнер собирается с дополнительными параметрами  
`├─ ./custom.ini` Дополнительные настройки PHP  
`├─ ./init.sh` Измененная точка запуска контейнера  
`└─ logs` Журналы /var/log  
`swagger` Каталог документации Swagger
`./.env-common` Файл, который отсутствует на GIT, но должен быть заполнен для запуска коллекции  
`./docker-compose.yml` Файл коллекции Docker  

## Установка
Для развертывания всего программного комплекса WebAPPs достаточно хоста с 2 ядрами процессора и 2 гигабайтами ОЗУ.  
Разработка и отладка проекта ведется на системе с Ubuntu, поэтому все этапы установки описаны для этой ОС, но
так как решение оформлено в виде докер-коллекции, то его можно легко портировать на другие операционные системы.
### Минимальный набор необходимого ПО
- пакет `docker-compose-v2`:
`sudo apt install docker-compose-v2`

## Шаги развертывания инфраструктуры Decoder
### Тестовая среда
В примерах развертывания инфраструктуры декодера используется тот же хост, на котором развернута инфраструктура WebAPPs.  
будет использоваться адрес сервера `deco.qwer.kz` и локальный каталог хост-системы `/opt/decoder`:
```
sudo mkdir /opt/decoder
sudo chmod 777 /opt/decoder
```
Все действия выполняются с правами пользователя, который заранее был довален в системные группы `docker` и `www-data`:
`sudo usermod -aG docker $USER`
`sudo usermod -aG www-data $USER`

### Загрузка файлов проекта
Загрузите и разместите файлы проекта в локальном каталоге хоста:
```
cd /opt/decoder
git config --global --add safe.directory /opt/decoder
git init
git pull https://github.com/antoshkin-dev/decoder.git
```

### Настройка сертификатов веб-сервера
1. Придумайте адрес для сервера Decoder в вашей инфраструктуре и выпустите для него сертификаты;
2. Разместите сертификаты веб-сервера в каталоге `./nginx/certs`
3. Скорректируйте имя сервера, путь до файлов-сертификаторв в файле конфигурации NGINX
`nginx/conf.d/sites-enabled/decoder.conf`.

Для запуска тестовой среды, можно восспользоваться сертификатами, выпущенными утилитой mkcert, аналогично, как и для основной
инфраструктуры WebAPPs:
```
cd /opt/decoder/nginx/certs
mkcert deco.qwer.kz
```
Убедитесь, что сертификаты созданы и запомните их имена
```
ls /opt/decoder/nginx/certs
```
вывод:
```
deco.qwer.kz-key.pem  deco.qwer.kz.pem
```
Поправьте настройки в конфигурационном файле NGINX `/opt/decoder/nginx/conf.d/sites-enabled/decoder.conf`:
```
...
server_name deco.qwer.kz;
...
ssl_certificate     /etc/nginx/certs/deco.qwer.kz.pem;
ssl_certificate_key /etc/nginx/certs/deco.qwer.kz-key.pem;
```
и вернитесь в корень проекта для продолжения установки `cd /opt/decoder`
### Настройка параметров в файле-коллекции
Отредактируйте `./docker-compose.yml`
- желаемая подсеть Docker;
- dns с которым будут работать контейнеры PHP;
- публикуемые сетевые порты;
- при размещении декодера на том же хосте, что и WebAPPS, укажите имя сервера в разделе сетевых альясов:  
```  
      shared:
        aliases:
          - deco.qwer.kz
```
### Изменения настроек PHP
Отредактируйте  `./php/custom.ini` под свою среду, например, изменив часовой пояс.  

### Сборка коллекции
`docker compose up --build -d`
После окончания процесса сборки, убедитесь, что все контейнеры успешно запущены `docker container ls`:
```
6324ada76681   nginx:1.25-alpine   "/docker-entrypoint.…"   44 seconds ago   Up 39 seconds   80/tcp, 0.0.0.0:8443->443/tcp, [::]:8443->443/tcp                              decoder-nginx
5c39de96182c   redis:7-alpine      "docker-entrypoint.s…"   46 seconds ago   Up 40 seconds   6379/tcp                                                                       decoder-redis
32e45cec76f4   decoder-phpdeco     "docker-php-entrypoi…"   46 seconds ago   Up 40 seconds   9000/tcp                                                                       decoder-phpfpm
```

### Настройка CRON
Для корректной работы декодера, следует настроить корректные пути в файле задач CRON в коллекции WebAPPs, для этого следует
отредактировать следующие строки в файле `./cron/crontab` инфраструктуры WebAPPs (для тестовой среды это `/opt/webapp/cron/crontab`):
```
# Обслуживание декодера
*/1  * * * * curl -s -o /var/log/tick.log 'https://decor.qwer.kz/cron.php?action=tick'
*/15 * * * * curl -s -o /var/log/resetsess.log 'https://deco.qwer.kz/cron.php?action=resetsesscounters'
```
После внесения измений, следует перезагрузить контейнер CRON: `docker container restart av-cron`

### Дальнейшие шаги
- Пропишите в ДНС-записях вашей сетевой инфраструктуры адрес до хоста с decoder, например `deco.qwer.kz A IN 192.168.0.10`;
- В админ-паннеле WebAPPs, в справочнике глобальных переменных, задайте адрес decoder-сервера и его порт. 
Затем перейдите в раздел "Декодер-Активация мастер-ключей" и установите мастер ключ для PowerVault.  
