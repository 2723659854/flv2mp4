# 用ffplay模拟多个客户端拉流
for i in {1..10}; do
    ffplay -loglevel quiet http://127.0.0.1:8080/a/b.flv &
done

# 观察网关日志中的客户端数和CPU