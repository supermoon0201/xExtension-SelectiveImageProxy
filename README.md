# Selective Image Proxy

只对指定 Feed ID 的文章图片启用代理。

## 安装

把整个目录放到 FreshRSS 的 `./extensions/xExtension-SelectiveImageProxy/`。

目录名建议改成：

```text
xExtension-SelectiveImageProxy
```

然后到 `Configuration / Extensions` 启用它。

## 配置

启用后点击齿轮图标：

- 填入目标 Feed ID 列表
- 填入图片代理前缀
- 确认需要代理的协议已勾选
- 保存

## 注意

- 这是按 **Feed ID** 生效，不是按域名。
- 代理服务不要裸奔到公网。
- 请阻止代理访问内网、本机和元数据地址。
