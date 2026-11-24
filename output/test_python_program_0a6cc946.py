#!/usr/bin/env python3
"""
Fibonacci generator test module for GitType background typing.
"""

def fibonacci(limit: int):
    a, b = 0, 1
    while a < limit:
        yield a
        a, b = b, a + b

if __name__ == "__main__":
    print("Fibonacci series up to 50:")
    for num in fibonacci(50):
        print(f" - {num}")
